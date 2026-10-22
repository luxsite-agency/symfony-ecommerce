<?php

declare(strict_types=1);

namespace App\Tests\Core\Shipping;

use App\Core\Shipping\NovaPoshta\Exception\NovaPoshtaApiException;
use App\Core\Shipping\NovaPoshta\Exception\NovaPoshtaDisabledException;
use App\Core\Shipping\NovaPoshta\NovaPoshtaClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Клієнт API НП: формат запиту, success=false → виняток, порожній ключ → вимкнено. HTTP мокається. */
final class NovaPoshtaClientTest extends TestCase
{
    public function testCitiesPostsJsonRpcLikeBody(): void
    {
        $captured = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'body' => json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR)];

            return new MockResponse(json_encode(['success' => true, 'data' => [['Ref' => 'c1', 'Description' => 'Київ']], 'errors' => []], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });
        $np = new NovaPoshtaClient($client, 'secret-key');

        self::assertTrue($np->isEnabled());
        self::assertSame([['Ref' => 'c1', 'Description' => 'Київ']], $np->cities(2, 500));
        self::assertSame('POST', $captured['method']);
        self::assertSame(NovaPoshtaClient::ENDPOINT, $captured['url']);
        self::assertSame(['apiKey' => 'secret-key', 'modelName' => 'Address', 'calledMethod' => 'getCities', 'methodProperties' => ['Page' => 2, 'Limit' => 500]], $captured['body']);
    }

    public function testApiErrorBecomesException(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"success": false, "data": [], "errors": ["API key incorrect"]}', ['response_headers' => ['content-type' => 'application/json']]));
        $this->expectException(NovaPoshtaApiException::class);
        $this->expectExceptionMessage('API key incorrect');
        (new NovaPoshtaClient($client, 'bad'))->warehouses(1, 100);
    }

    public function testEmptyKeyDisablesClient(): void
    {
        $np = new NovaPoshtaClient(new MockHttpClient(), '');
        self::assertFalse($np->isEnabled());
        $this->expectException(NovaPoshtaDisabledException::class);
        $np->cities(1, 10);
    }
}
