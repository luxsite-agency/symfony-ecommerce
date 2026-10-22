<?php

declare(strict_types=1);

namespace App\Tests\Core\Shared;

use App\Core\Shared\Log\WebhookTokenMaskProcessor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class WebhookTokenMaskProcessorTest extends TestCase
{
    public function testMasksTokenInMessageContextAndExtra(): void
    {
        $record = new LogRecord(new DateTimeImmutable(), 'request', Level::Error, 'Uncaught at POST /webhooks/crm/stock?token=abc123&x=1', [
            'request_uri' => '/webhooks/crm/stock?token=abc123',
            'nested' => ['url' => 'https://shop.example/webhooks/crm/stock?x=1&token=abc123', 'n' => 5],
        ], ['other' => 'token=zzz']);
        $masked = (new WebhookTokenMaskProcessor())($record);
        self::assertSame('Uncaught at POST /webhooks/crm/stock?token=***&x=1', $masked->message);
        self::assertSame('/webhooks/crm/stock?token=***', $masked->context['request_uri']);
        self::assertSame('https://shop.example/webhooks/crm/stock?x=1&token=***', $masked->context['nested']['url']);
        self::assertSame(5, $masked->context['nested']['n']);
        self::assertSame('token=***', $masked->extra['other']);
        self::assertStringNotContainsString('abc123', json_encode($masked->toArray(), \JSON_THROW_ON_ERROR));

        $plain = new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'nothing here', ['a' => 1]);
        self::assertSame($plain, (new WebhookTokenMaskProcessor())($plain), 'без токена запис той самий обʼєкт');
    }
}
