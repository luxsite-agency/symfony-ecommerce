<?php

declare(strict_types=1);

namespace App\Tests\Functional\Integration;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Integration\Crm\Webhook\CrmWebhookToken;
use App\Core\Integration\Crm\Webhook\WebhookTokens;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** POST /webhooks/crm/stock з активним KeyCRM: токен, застосування, невідомий sku, ідемпотентність. */
final class CrmWebhookTest extends WebTestCase
{
    /** @var array<string, string|null> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        foreach (['CRM_CONNECTOR' => 'keycrm', 'CRM_WEBHOOK_TOKEN' => 'hook-secret'] as $name => $value) {
            $this->previousEnv[$name] = isset($_SERVER[$name]) ? (string) $_SERVER[$name] : null;
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
    }

    /** Повертаємо значення з .env, а не прибираємо: без CRM_CONNECTOR наступні тести з адмінкою в цьому ж процесі не зберуть DashboardController. */
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->previousEnv as $name => $value) {
            if (null === $value) {
                unset($_SERVER[$name], $_ENV[$name]);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
            }
        }
    }

    public function testStockWebhookAppliesLevelsAndReportsUnknownSku(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new Product('WH-1', 'UAH'));
        $em->flush();

        $payload = json_encode([['offer_id' => 5, 'sku' => 'WH-1', 'in_stock' => 9, 'in_reserve' => 2], ['offer_id' => 6, 'sku' => 'GHOST', 'in_stock' => 1, 'in_reserve' => 0]], \JSON_THROW_ON_ERROR);
        $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer hook-secret'], content: $payload);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['received' => 2, 'changed' => 1, 'unknown' => ['GHOST']], ['received' => $body['received'], 'changed' => $body['changed'], 'unknown' => $body['unknown']]);
        self::assertSame('7.000', $em->getRepository(ProductVariant::class)->findOneBy(['sku' => 'WH-1'])?->getStock());

        // основний спосіб KeyCRM: токен у query
        $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock?token=hook-secret', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['changed'], 'Повтор того самого payload нічого не змінює');
        self::assertSame(1, $body['unchanged']);
    }

    /** Ротація: новий токен чинний одразу, попередній (тут env) ще 24 год, після другої ротації перший гасне. */
    public function testRotationKeepsPreviousTokenForGracePeriod(): void
    {
        $client = static::createClient();
        $tokens = static::getContainer()->get(WebhookTokens::class);
        $first = $tokens->rotate();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $first);
        foreach (['hook-secret', $first] as $token) {
            $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock?token='.$token, content: '[]');
            self::assertResponseIsSuccessful($token === $first ? 'новий' : 'env-токен у перехідний період');
        }
        $second = $tokens->rotate();
        $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock?token='.$second, content: '[]');
        self::assertResponseIsSuccessful();
        $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock?token='.$first, content: '[]');
        self::assertResponseIsSuccessful('попередній ще в перехідному періоді');
        self::assertSame(hash('sha256', $first), $this->retired($first)->getTokenHash());
        self::assertNotNull($this->retired($first)->getExpiresAt());

        // перехідний період минув
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('UPDATE '.CrmWebhookToken::class.' t SET t.expiresAt = :past WHERE t.expiresAt IS NOT NULL')->setParameter('past', new DateTimeImmutable('-1 minute'))->execute();
        $em->clear();
        $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock?token='.$first, content: '[]');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock?token=hook-secret', content: '[]');
        self::assertResponseStatusCodeSame(403, 'env-токен після ротації й 24 год не діє');
        $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock?token='.$second, content: '[]');
        self::assertResponseIsSuccessful();

        $output = new BufferedOutput();
        (new Application(static::$kernel ?? self::bootKernel()))->find('crm:webhook:rotate-token')->run(new ArrayInput([]), $output);
        self::assertStringContainsString('/webhooks/crm/stock?token=', $output->fetch());
    }

    public function testWebhookIsRateLimitedPerIp(): void
    {
        $client = static::createClient();
        $ip = '198.51.100.'.random_int(1, 254);
        $client->setServerParameter('REMOTE_ADDR', $ip);
        static::getContainer()->get('limiter.crm_webhook_ip')->create($ip)->reset();
        // правильний токен не списує ліміт: масове оновлення залишків з KeyCRM проходить (рев'ю #56, п. 2)
        for ($i = 0; $i < 70; ++$i) {
            $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock?token=hook-secret', content: '[]');
            self::assertResponseIsSuccessful();
        }
        for ($i = 0; $i < 60; ++$i) {
            $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock?token=nope', content: '[]');
            self::assertResponseStatusCodeSame(403);
        }
        $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock?token=nope', content: '[]');
        self::assertResponseStatusCodeSame(429, 'після 60 невдалих спроб за хвилину — 429');
        self::assertMatchesRegularExpression('/^\d+$/', (string) $client->getResponse()->headers->get('Retry-After'));
        $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock?token=hook-secret', content: '[]');
        self::assertResponseStatusCodeSame(429, 'поки ліміт невдалих не минув, чекає й правильний: перебір не має підказки, коли вгадав');
        static::getContainer()->get('limiter.crm_webhook_ip')->create($ip)->reset();
        $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock?token=hook-secret', content: '[]');
        self::assertResponseIsSuccessful('після вікна правильний токен проходить');
    }

    private function retired(string $token): CrmWebhookToken
    {
        $row = static::getContainer()->get(EntityManagerInterface::class)->getRepository(CrmWebhookToken::class)->findOneBy(['tokenHash' => hash('sha256', $token)]);
        self::assertInstanceOf(CrmWebhookToken::class, $row);

        return $row;
    }

    public function testWrongTokenIsForbidden(): void
    {
        $client = static::createClient();
        $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock', server: ['HTTP_AUTHORIZATION' => 'Bearer nope'], content: '[]');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock?token=nope', content: '[]');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock', content: '[]');
        self::assertResponseStatusCodeSame(403);
    }
}
