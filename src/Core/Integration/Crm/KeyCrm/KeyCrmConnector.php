<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Crm\KeyCrm;

use App\Core\Integration\Crm\CrmCapabilities;
use App\Core\Integration\Crm\CrmConnectorInterface;
use App\Core\Integration\Crm\CrmOrderRef;
use App\Core\Integration\Crm\Exception\CrmNotSupportedException;
use App\Core\Integration\Crm\Exception\WebhookRejectedException;
use App\Core\Integration\Crm\OrderExport;
use App\Core\Integration\Crm\StockLevel;
use App\Core\Integration\Crm\Webhook\WebhookTokens;
use Closure;
use JsonException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * KeyCRM (05-keycrm-contract.md), перша реалізація — лише читання залишків:
 *  - pullStock: GET /offers/stocks посторінково (limit 50) з паузою під rate limit (60/хв → 1 с);
 *  - вебхук залишків: POST JSON [{offer_id, sku, in_stock, in_reserve}], токен у query ?token= (підпису KeyCRM не описує).
 * pushOrder — Ф2. Ключ API лише з env KEYCRM_API_KEY.
 */
#[AsTaggedItem(index: 'keycrm')]
final class KeyCrmConnector implements CrmConnectorInterface
{
    public const string SYSTEM = 'keycrm';
    private const int PAGE_SIZE = 50;

    private Closure $sleep;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(KEYCRM_API_KEY)%')] private readonly string $apiKey,
        private readonly WebhookTokens $webhookTokens,
        #[Autowire('%env(KEYCRM_BASE_URL)%')] private readonly string $baseUrl = 'https://openapi.keycrm.app/v1/',
        #[Autowire('%env(int:KEYCRM_RATE_PAUSE_MS)%')] private readonly int $ratePauseMs = 1000,
        #[Autowire('%env(KEYCRM_SOURCE_ID)%')] private readonly string $sourceId = '',
        ?callable $sleep = null,
        #[Autowire('%env(default::KEYCRM_DELIVERY_SERVICE_NP)%')] private readonly ?string $deliveryServiceNovaPoshta = null,
    ) {
        $this->sleep = null !== $sleep ? $sleep(...) : static fn (int $ms): null => 0 === $ms ? null : usleep($ms * 1000);
    }

    public function getSystem(): string
    {
        return self::SYSTEM;
    }

    public function capabilities(): CrmCapabilities
    {
        return new CrmCapabilities(stockWebhook: true, orderWebhook: false, priceSync: false, deltaImport: false, pushOrder: '' !== $this->apiKey);
    }

    /**
     * POST /order (контракт §1): source_id магазину, buyer, products по sku з ціною сайту, shipping, payments, коментар.
     * Відмова API (4xx/5xx) — виняток → retry Messenger → failed transport + алерт; замовлення на сайті лишається new.
     */
    public function pushOrder(OrderExport $order): CrmOrderRef
    {
        if ('' === $this->apiKey) {
            throw new CrmNotSupportedException('KEYCRM_API_KEY не задано');
        }
        $payload = [
            'source_id' => '' === $this->sourceId ? null : (int) $this->sourceId,
            'source_uuid' => $order->siteOrderNumber,
            'buyer_comment' => $order->comment,
            'buyer' => array_filter(['full_name' => $order->buyer['name'] ?? null, 'phone' => $order->buyer['phone'] ?? null, 'email' => $order->buyer['email'] ?? null]),
            // НП: id служби доставки з налаштувань KeyCRM (env KEYCRM_DELIVERY_SERVICE_NP) і Ref відділення з снепшота замовлення
            'shipping' => array_filter([
                'delivery_service_id' => 'nova_poshta' === ($order->shipping['method'] ?? null) && null !== $this->deliveryServiceNovaPoshta && '' !== $this->deliveryServiceNovaPoshta ? (int) $this->deliveryServiceNovaPoshta : null,
                'shipping_address_city' => $order->shipping['city'] ?? null,
                'shipping_receive_point' => $order->shipping['address'] ?? null,
                'warehouse_ref' => $order->shipping['np_warehouse_ref'] ?? null,
                'recipient_full_name' => $order->buyer['name'] ?? null,
                'recipient_phone' => $order->buyer['phone'] ?? null,
            ]),
            'products' => array_map(static fn (array $item): array => ['sku' => $item['sku'], 'price' => (float) bcdiv((string) $item['unitPriceMinor'], '100', 2), 'quantity' => (float) $item['quantity']], $order->items),
            // сума — збережений total замовлення (консиліум PR #6 п. 8), не перерахунок із truncation
            'payments' => [['payment_method' => $order->payment['method'] ?? 'cod', 'amount' => (float) bcdiv((string) $order->totalMinor, '100', 2), 'status' => 'not_paid']],
        ];
        $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/order', [
            'headers' => ['Authorization' => 'Bearer '.$this->apiKey, 'Accept' => 'application/json'],
            'json' => array_filter($payload, static fn ($v): bool => null !== $v),
        ]);
        try {
            /** @var array{id?: int|string} $body */
            $body = $response->toArray();
        } catch (ClientExceptionInterface $e) {
            // 4xx (крім 429) — постійна помилка запиту: ретрай не допоможе, одразу у failed (консиліум PR #6 п. 11)
            if (429 !== $e->getResponse()->getStatusCode()) {
                throw new UnrecoverableMessageHandlingException(\sprintf('KeyCRM відхилив замовлення %s: HTTP %d', $order->siteOrderNumber, $e->getResponse()->getStatusCode()), 0, $e);
            }
            throw $e;
        }
        $id = isset($body['id']) && '' !== (string) $body['id'] ? (string) $body['id'] : null;
        if (null === $id) {
            // 2xx без id — не «успіх без прив'язки», а порушення контракту: виняток → retry → failed → алерт (п. 9)
            throw new RuntimeException(\sprintf('KeyCRM: відповідь на POST /order без id (замовлення %s)', $order->siteOrderNumber));
        }

        return CrmOrderRef::of(self::SYSTEM, $id);
    }

    /** @param iterable<string> $skus */
    public function pullStock(iterable $skus = []): iterable
    {
        if ('' === $this->apiKey) {
            throw new CrmNotSupportedException('KEYCRM_API_KEY не задано');
        }
        $wanted = [];
        foreach ($skus as $sku) {
            $wanted[$sku] = true;
        }

        for ($page = 1;; ++$page) {
            if ($page > 1) {
                ($this->sleep)($this->ratePauseMs);
            }
            $response = $this->httpClient->request('GET', rtrim($this->baseUrl, '/').'/offers/stocks', [
                'headers' => ['Authorization' => 'Bearer '.$this->apiKey, 'Accept' => 'application/json'],
                'query' => ['limit' => self::PAGE_SIZE, 'page' => $page],
            ]);
            /** @var array{data?: list<array<string, mixed>>, next_page_url?: string|null, last_page?: int} $body */
            $body = $response->toArray();
            $rows = $body['data'] ?? [];
            foreach ($rows as $row) {
                $sku = trim((string) ($row['sku'] ?? ''));
                if ('' === $sku || ([] !== $wanted && !isset($wanted[$sku]))) {
                    continue;
                }
                yield self::level($row, $sku);
            }
            $hasNext = null !== ($body['next_page_url'] ?? null) || (isset($body['last_page']) && $page < (int) $body['last_page']);
            if ([] === $rows || !$hasNext) {
                return;
            }
        }
    }

    /**
     * Токен вебхука: `?token=` (KeyCRM шле лише URL + POST, власних заголовків не підтримує) або додатково
     * `Authorization: Bearer`. Чинні токени, ротація й перехідний період — WebhookTokens (консиліум безпеки 10.10, п. 8).
     */
    public function handleWebhook(Request $request): iterable
    {
        if (!$this->webhookTokens->isValid(WebhookTokens::presented($request))) {
            throw new WebhookRejectedException('Недійсний токен вебхука', 403);
        }
        try {
            $payload = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new WebhookRejectedException('Тіло вебхука — не JSON: '.$e->getMessage());
        }
        if (!\is_array($payload)) {
            throw new WebhookRejectedException('Очікується масив рівнів залишку');
        }
        if (isset($payload['sku']) || isset($payload['offer_id'])) {
            $payload = [$payload];
        }
        $levels = [];
        foreach ($payload as $i => $row) {
            if (!\is_array($row) || '' === trim((string) ($row['sku'] ?? ''))) {
                throw new WebhookRejectedException(\sprintf('Рядок %d без sku', (int) $i));
            }
            $levels[] = self::level($row, trim((string) $row['sku']));
        }

        return $levels;
    }

    /** @param array<string, mixed> $row */
    private static function level(array $row, string $sku): StockLevel
    {
        $inStock = $row['in_stock'] ?? $row['quantity'] ?? 0;
        $inReserve = $row['in_reserve'] ?? $row['reserve'] ?? 0;
        $offerId = $row['offer_id'] ?? $row['id'] ?? null;

        return new StockLevel(
            sku: $sku,
            inStock: (string) (is_numeric($inStock) ? $inStock : 0),
            inReserve: (string) (is_numeric($inReserve) ? $inReserve : 0),
            externalId: null === $offerId || '' === (string) $offerId ? null : (string) $offerId,
        );
    }
}
