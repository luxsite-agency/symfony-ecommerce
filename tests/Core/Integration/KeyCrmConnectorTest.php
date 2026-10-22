<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration;

use App\Core\Integration\Crm\Exception\CrmNotSupportedException;
use App\Core\Integration\Crm\Exception\WebhookRejectedException;
use App\Core\Integration\Crm\KeyCrm\KeyCrmConnector;
use App\Core\Integration\Crm\OrderExport;
use App\Core\Integration\Crm\StockLevel;
use App\Core\Integration\Crm\Webhook\WebhookTokens;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

/** KeyCRM: пагінація GET /offers/stocks з паузою, фільтр по sku, розбір вебхука з токеном. */
final class KeyCrmConnectorTest extends TestCase
{
    /** @var list<int> */
    private array $pauses = [];

    public function testPullStockPaginatesWithPauseAndBearer(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options['normalized_headers']['authorization'][0] ?? ''];
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);
            $data = match ($page) {
                1 => [['offer_id' => 1017, 'sku' => 'A-1', 'in_stock' => 10, 'in_reserve' => 2], ['offer_id' => 1018, 'sku' => 'A-2', 'in_stock' => 0, 'in_reserve' => 1]],
                2 => [['offer_id' => 1019, 'sku' => 'A-3', 'in_stock' => 5, 'in_reserve' => 0]],
                default => [],
            };

            return new MockResponse(json_encode(['data' => $data, 'last_page' => 2, 'next_page_url' => 2 === $page ? null : 'next'], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });
        $connector = $this->connector($client);

        $levels = iterator_to_array($connector->pullStock(), false);

        self::assertCount(3, $levels);
        self::assertSame(['A-1', 'A-2', 'A-3'], array_map(static fn (StockLevel $l): string => $l->sku, $levels));
        self::assertSame(['8.000', '0.000', '5.000'], array_map(static fn (StockLevel $l): string => $l->available, $levels));
        self::assertTrue($levels[1]->clamped);
        self::assertSame('1017', $levels[0]->externalId);

        self::assertCount(2, $requests, 'Дві сторінки — два запити');
        self::assertSame('Authorization: Bearer test-key', $requests[0][2]);
        self::assertStringContainsString('/offers/stocks?limit=50&page=1', $requests[0][1]);
        self::assertSame([1000], $this->pauses, 'Пауза перед кожною наступною сторінкою');
    }

    public function testPullStockFiltersBySku(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['data' => [
            ['offer_id' => 1, 'sku' => 'A-1', 'in_stock' => 1, 'in_reserve' => 0],
            ['offer_id' => 2, 'sku' => 'A-2', 'in_stock' => 1, 'in_reserve' => 0],
        ], 'last_page' => 1], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]));

        $levels = iterator_to_array($this->connector($client)->pullStock(['A-2']), false);
        self::assertSame(['A-2'], array_map(static fn (StockLevel $l): string => $l->sku, $levels));
    }

    public function testPullStockWithoutApiKeyIsNotSupported(): void
    {
        $connector = new KeyCrmConnector(new MockHttpClient(), '', $this->tokens('tok'), 'https://x/v1/', 0, '', static fn (int $ms) => null);
        $this->expectException(CrmNotSupportedException::class);
        iterator_to_array($connector->pullStock(), false);
    }

    public function testPushOrderPostsOrderAndReturnsCrmId(): void
    {
        $captured = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'body' => json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR), 'auth' => $options['normalized_headers']['authorization'][0] ?? ''];

            return new MockResponse(json_encode(['id' => 5501], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });
        $export = new OrderExport('2609-000012', 'UAH', [['sku' => 'ML-14-WHITE', 'quantity' => '2.000', 'unitPriceMinor' => 426_200, 'lineTotalMinor' => 852_400]], ['name' => 'Олена', 'phone' => '+380671234567', 'email' => null], ['method' => 'nova_poshta', 'city' => 'Київ', 'address' => 'відділення 12'], ['method' => 'cod', 'status' => 'pending'], 'Подзвоніть після 18:00', 852_400, 0, 852_400);

        $ref = $this->connector($client)->pushOrder($export);

        self::assertSame('5501', $ref->externalOrderId);
        self::assertSame('keycrm', $ref->system);
        self::assertSame('POST', $captured['method']);
        self::assertStringEndsWith('/order', $captured['url']);
        self::assertSame('Authorization: Bearer test-key', $captured['auth']);
        self::assertSame(7, $captured['body']['source_id']);
        self::assertSame('2609-000012', $captured['body']['source_uuid']);
        self::assertSame(['full_name' => 'Олена', 'phone' => '+380671234567'], $captured['body']['buyer']);
        self::assertSame([['sku' => 'ML-14-WHITE', 'price' => 4262.0, 'quantity' => 2.0]], $captured['body']['products']);
        self::assertSame(8524.0, $captured['body']['payments'][0]['amount']);
        self::assertSame('Київ', $captured['body']['shipping']['shipping_address_city']);
    }

    /** Консиліум PR #6 п. 8: сума платежу — збережений total замовлення, а не перерахунок ціна×кількість із truncation. */
    public function testPushOrderPaymentAmountIsTheStoredTotal(): void
    {
        $captured = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return new MockResponse('{"id": 7}', ['response_headers' => ['content-type' => 'application/json']]);
        });
        // 10.01 × 1.5 = 15.015 → у замовленні 15.02 (округлення Money), truncation дав би 15.01
        $export = new OrderExport('2609-000013', 'UAH', [['sku' => 'A', 'quantity' => '1.500', 'unitPriceMinor' => 1_001, 'lineTotalMinor' => 1_502]], itemsTotalMinor: 1_502, shippingMinor: 0, totalMinor: 1_502);
        $this->connector($client)->pushOrder($export);
        self::assertSame(15.02, $captured['payments'][0]['amount']);
    }

    /** Ф3: замовлення з відділенням НП — delivery_service_id з env і warehouse_ref зі снепшота. */
    public function testPushOrderSendsNovaPoshtaWarehouseRef(): void
    {
        $captured = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return new MockResponse('{"id": 8}', ['response_headers' => ['content-type' => 'application/json']]);
        });
        $connector = new KeyCrmConnector($client, 'test-key', $this->tokens('secret'), 'https://openapi.keycrm.app/v1/', 0, '7', static fn (int $ms) => null, '4');
        $connector->pushOrder(new OrderExport('2609-000020', 'UAH', [], ['name' => 'Ірина', 'phone' => '+380501112233'], ['method' => 'nova_poshta', 'city' => 'Київ', 'address' => 'Відділення №2: вул. Богатирська, 11', 'np_city_ref' => 'c-kyiv', 'np_warehouse_ref' => 'w-2', 'np_warehouse_number' => '2']));
        self::assertSame(4, $captured['shipping']['delivery_service_id']);
        self::assertSame('w-2', $captured['shipping']['warehouse_ref']);
        self::assertSame('Відділення №2: вул. Богатирська, 11', $captured['shipping']['shipping_receive_point']);

        // без env і без довідника — як раніше: ні delivery_service_id, ні warehouse_ref
        $this->connector($client)->pushOrder(new OrderExport('2609-000021', 'UAH', [], [], ['method' => 'nova_poshta', 'city' => 'Київ', 'address' => 'відділення 12', 'np_warehouse_ref' => null]));
        self::assertArrayNotHasKey('delivery_service_id', $captured['shipping']);
        self::assertArrayNotHasKey('warehouse_ref', $captured['shipping']);
    }

    /** п. 9: 2xx без id — порушення контракту, а не «успіх без прив'язки». */
    public function testPushOrderWithoutIdInResponseFails(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"status": "ok"}', ['response_headers' => ['content-type' => 'application/json']]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('без id');
        $this->connector($client)->pushOrder(new OrderExport('2609-000014', 'UAH', []));
    }

    /** п. 11: 4xx (валідація, авторизація) — не ретраїти: UnrecoverableMessageHandlingException одразу у failed. */
    public function testPushOrder4xxIsUnrecoverable(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"message": "sku not found"}', ['http_code' => 422, 'response_headers' => ['content-type' => 'application/json']]));
        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('HTTP 422');
        $this->connector($client)->pushOrder(new OrderExport('2609-000015', 'UAH', []));
    }

    /** п. 11: 429 — тимчасове, лишається ретраєм Messenger. */
    public function testPushOrder429StaysRetryable(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 429]));
        $this->expectException(ClientExceptionInterface::class);
        $this->connector($client)->pushOrder(new OrderExport('2609-000016', 'UAH', []));
    }

    public function testPushOrderWithoutApiKeyIsNotSupported(): void
    {
        $connector = new KeyCrmConnector(new MockHttpClient(), '', $this->tokens('tok'), 'https://x/v1/', 0, '', static fn (int $ms) => null);
        self::assertFalse($connector->capabilities()->pushOrder);
        $this->expectException(CrmNotSupportedException::class);
        $connector->pushOrder(new OrderExport('1', 'UAH', []));
    }

    public function testWebhookRequiresToken(): void
    {
        $this->expectException(WebhookRejectedException::class);
        $this->connector(new MockHttpClient())->handleWebhook(Request::create('/webhooks/crm/stock', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer wrong'], content: '[]'));
    }

    /** Токен у ?token= (KeyCRM інакше не вміє) або додатково в Authorization: Bearer; заголовок має перевагу. */
    public function testWebhookTokenInQueryOrHeader(): void
    {
        $connector = $this->connector(new MockHttpClient());
        self::assertSame([], iterator_to_array($connector->handleWebhook(Request::create('/webhooks/crm/stock?token=secret', 'POST', content: '[]')), false));
        self::assertSame([], iterator_to_array($connector->handleWebhook(Request::create('/webhooks/crm/stock', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer secret'], content: '[]')), false));

        $this->expectException(WebhookRejectedException::class);
        $connector->handleWebhook(Request::create('/webhooks/crm/stock?token=secret', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer wrong'], content: '[]'));
    }

    public function testWebhookParsesStockLevels(): void
    {
        $body = json_encode([['offer_id' => 1017, 'sku' => 'A-1', 'in_stock' => 10, 'in_reserve' => 4], ['offer_id' => 1018, 'sku' => 'A-2', 'in_stock' => 1, 'in_reserve' => 3]], \JSON_THROW_ON_ERROR);
        $levels = iterator_to_array($this->connector(new MockHttpClient())->handleWebhook(Request::create('/webhooks/crm/stock', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer secret'], content: $body)), false);

        self::assertCount(2, $levels);
        self::assertSame('6.000', $levels[0]->available);
        self::assertSame('0.000', $levels[1]->available);
        self::assertSame('1018', $levels[1]->externalId);
    }

    public function testWebhookRejectsRowWithoutSku(): void
    {
        $this->expectException(WebhookRejectedException::class);
        $this->connector(new MockHttpClient())->handleWebhook(Request::create('/webhooks/crm/stock', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer secret'], content: '[{"offer_id": 1}]'));
    }

    private function connector(MockHttpClient $client): KeyCrmConnector
    {
        return new KeyCrmConnector($client, 'test-key', $this->tokens('secret'), 'https://openapi.keycrm.app/v1/', 1000, '7', function (int $ms): void {
            $this->pauses[] = $ms;
        });
    }

    /** WebhookTokens без ротацій: у БД порожньо, чинний токен — env. */
    private function tokens(string $envToken): WebhookTokens
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn([]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return new WebhookTokens($em, $envToken);
    }
}
