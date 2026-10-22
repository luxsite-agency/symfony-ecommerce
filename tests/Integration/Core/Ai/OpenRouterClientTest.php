<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Ai;

use App\Core\Ai\Dto\ChatRequest;
use App\Core\Ai\Exception\AiAuthException;
use App\Core\Ai\Exception\AiNotConfiguredException;
use App\Core\Ai\Exception\AiPaymentRequiredException;
use App\Core\Ai\Exception\AiProviderException;
use App\Core\Ai\Exception\AiRateLimitedException;
use App\Core\Ai\OpenRouterClient;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** E15 BYOK: клієнт OpenRouter — ключ у Bearer, usage.cost у запиті, типізовані помилки без тіла відповіді. */
final class OpenRouterClientTest extends KernelTestCase
{
    private Settings $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->settings = self::getContainer()->get(Settings::class);
        $this->settings->setSecret(SettingKeys::AI_OPENROUTER_KEY, 'sk-or-v1-testkey-0000');
    }

    /** @param list<MockResponse>|callable $responses */
    private function client(array|callable $responses): OpenRouterClient
    {
        return new OpenRouterClient(new MockHttpClient($responses), $this->settings, 'https://openrouter.test/api/v1/', 7, 'shop.example.test');
    }

    public function testChatSendsBearerAndUsageIncludeAndParsesCost(): void
    {
        $captured = [];
        $client = $this->client(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $options['normalized_headers'], 'body' => json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR), 'timeout' => $options['timeout'] ?? null];

            return new MockResponse(json_encode(['id' => 'gen-1', 'model' => 'deepseek/deepseek-v4-pro', 'choices' => [['message' => ['content' => 'Привіт'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3, 'cost' => 0.000123]], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });
        $response = $client->chat(new ChatRequest('deepseek/deepseek-v4-pro', 'Ти редактор', 'Скажи привіт', 500, ['type' => 'object', 'properties' => ['t' => ['type' => 'string']]]));

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://openrouter.test/api/v1/chat/completions', $captured['url']);
        self::assertSame(['Authorization: Bearer sk-or-v1-testkey-0000'], $captured['headers']['authorization']);
        self::assertSame(['HTTP-Referer: https://shop.example.test'], $captured['headers']['http-referer']);
        self::assertSame(['include' => true], $captured['body']['usage']);
        self::assertSame(500, $captured['body']['max_tokens']);
        self::assertSame('json_schema', $captured['body']['response_format']['type']);
        self::assertSame([['role' => 'system', 'content' => 'Ти редактор'], ['role' => 'user', 'content' => 'Скажи привіт']], $captured['body']['messages']);
        self::assertSame(7.0, (float) $captured['timeout']);

        self::assertSame('Привіт', $response->content);
        self::assertSame(12, $response->promptTokens);
        self::assertSame(3, $response->completionTokens);
        self::assertSame(0.000123, $response->cost);
        self::assertSame('gen-1', $response->providerId);
        self::assertSame('stop', $response->finishReason);
    }

    public function testKeyInfoParsesLimits(): void
    {
        $client = $this->client([new MockResponse(json_encode(['data' => ['label' => 'shop', 'limit' => 20, 'usage' => 3.5, 'limit_remaining' => 16.5, 'is_free_tier' => false]], \JSON_THROW_ON_ERROR))]);
        $info = $client->keyInfo();
        self::assertSame('shop', $info->label);
        self::assertSame(20.0, $info->limit);
        self::assertSame(3.5, $info->usage);
        self::assertSame(16.5, $info->limitRemaining);
        self::assertStringContainsString('ключ «shop», витрачено $3.50, ліміт $20.00, лишилось $16.50', $info->summary());
    }

    /** @return iterable<string, array{int, class-string, array<string, string>}> */
    public static function httpErrors(): iterable
    {
        yield '401' => [401, AiAuthException::class, []];
        yield '402' => [402, AiPaymentRequiredException::class, []];
        yield '429' => [429, AiRateLimitedException::class, ['retry-after' => '30']];
        yield '503' => [503, AiProviderException::class, []];
    }

    /**
     * @param class-string          $exception
     * @param array<string, string> $headers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('httpErrors')]
    public function testHttpErrorsBecomeTypedExceptionsWithoutEchoingSecrets(int $status, string $exception, array $headers): void
    {
        $client = $this->client([new MockResponse(json_encode(['error' => ['code' => $status, 'message' => 'Nope sk-or-v1-testkey-0000 long body '.str_repeat('x', 500)]], \JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => $headers])]);
        try {
            $client->keyInfo();
            self::fail('очікувався виняток');
        } catch (\App\Core\Ai\Exception\AiException $e) {
            self::assertInstanceOf($exception, $e);
            self::assertStringStartsWith('OpenRouter '.$status, $e->getMessage());
            self::assertLessThan(260, \strlen($e->getMessage()), 'повідомлення провайдера обрізане');
            if ($e instanceof AiRateLimitedException) {
                self::assertSame(30, $e->retryAfter);
                self::assertTrue($e->isRetryable());
            }
            if ($e instanceof AiAuthException || $e instanceof AiPaymentRequiredException) {
                self::assertFalse($e->isRetryable());
            }
        }
    }

    public function testNetworkFailureIsRetryableProviderError(): void
    {
        $client = $this->client([new MockResponse('', ['error' => 'Connection refused'])]);
        $this->expectException(AiProviderException::class);
        $client->keyInfo();
    }

    public function testWithoutKeyNothingIsSent(): void
    {
        $this->settings->setSecret(SettingKeys::AI_OPENROUTER_KEY, null);
        $client = $this->client(static function (): MockResponse {
            self::fail('без ключа запит не має йти');
        });
        $this->expectException(AiNotConfiguredException::class);
        $client->keyInfo();
    }
}
