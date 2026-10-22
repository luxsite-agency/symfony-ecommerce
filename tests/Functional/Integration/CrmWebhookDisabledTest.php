<?php

declare(strict_types=1);

namespace App\Tests\Functional\Integration;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Рішення власника 11.10.2026: вебхук KeyCRM вимкнений, коли CRM_CONNECTOR не keycrm (none, інша CRM) — 404 без журналу. */
final class CrmWebhookDisabledTest extends WebTestCase
{
    /** @var array<string, string|null> */
    private array $previousEnv = [];

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

    /** @return iterable<string, array{string}> */
    public static function connectors(): iterable
    {
        yield 'none' => ['none'];
        yield 'інша CRM без конектора' => ['othercrm'];
    }

    #[DataProvider('connectors')]
    public function testWebhookIsNotFoundAndNotLogged(string $connector): void
    {
        foreach (['CRM_CONNECTOR' => $connector, 'CRM_WEBHOOK_TOKEN' => 'hook-secret'] as $name => $value) {
            $this->previousEnv[$name] = isset($_SERVER[$name]) ? (string) $_SERVER[$name] : null;
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
        $client = static::createClient();
        $logger = static::getContainer()->get(LoggerInterface::class);
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($records = new TestHandler());
        try {
            foreach (['?token=hook-secret', '?token=wrong', ''] as $query) {
                $client->request('POST', 'https://shop-engine.localhost/webhooks/crm/stock'.$query, server: ['CONTENT_TYPE' => 'application/json'], content: '[]');
                self::assertResponseStatusCodeSame(404, $connector.' '.$query);
            }
        } finally {
            $logger->popHandler();
        }
        self::assertFalse($records->hasRecordThatContains('CRM webhook', Level::Warning), 'ні «відхилено», ні ліміту в журналі');
        self::assertFalse($records->hasRecordThatContains('webhook', Level::Info));
    }
}
