<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Ai;

use App\Core\Ai\Dto\ChatRequest;
use App\Core\Ai\Dto\ChatResponse;
use App\Core\Ai\Dto\KeyInfo;
use App\Core\Ai\Exception\AiAuthException;
use App\Core\Ai\Exception\AiException;
use App\Core\Ai\Exception\AiNotConfiguredException;
use App\Core\Ai\Exception\AiPaymentRequiredException;
use App\Core\Ai\Exception\AiProviderException;
use App\Core\Ai\Exception\AiRateLimitedException;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use JsonException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * OpenRouter (E15 BYOK): ключ клієнта з Settings (розшифровується тут і лише тут), OpenAI-сумісний /chat/completions
 * з usage.cost, GET /key для «Перевірити з'єднання». HTTP-помилки → типізовані винятки без тіла відповіді в message
 * (там може бути відлуння запиту); ключ у заголовку, в URL і логах його нема.
 */
final class OpenRouterClient implements AiChatClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Settings $settings,
        #[Autowire('%app.ai_base_url%')] private readonly string $baseUrl = 'https://openrouter.ai/api/v1/',
        #[Autowire('%app.ai_timeout%')] private readonly int $timeout = 60,
        #[Autowire('%site_host%')] private readonly string $siteHost = '',
    ) {
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $data = $this->call('POST', 'chat/completions', ['json' => $request->toPayload()]);
        $choice = $data['choices'][0] ?? null;
        $content = \is_array($choice) ? ($choice['message']['content'] ?? null) : null;
        if (!\is_string($content)) {
            throw new AiProviderException('OpenRouter: відповідь без тексту (choices[0].message.content)');
        }
        $usage = \is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $cost = $usage['cost'] ?? null;

        return new ChatResponse(
            content: $content,
            model: \is_string($data['model'] ?? null) ? $data['model'] : $request->model,
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            cost: is_numeric($cost) ? (float) $cost : null,
            providerId: \is_string($data['id'] ?? null) ? $data['id'] : null,
            finishReason: \is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null,
        );
    }

    public function keyInfo(): KeyInfo
    {
        $data = $this->call('GET', 'key');
        $inner = $data['data'] ?? null;
        /** @var array<string, mixed> $info */
        $info = \is_array($inner) ? $inner : $data;
        $num = static fn (mixed $v): ?float => is_numeric($v) ? (float) $v : null;

        return new KeyInfo(
            label: \is_string($info['label'] ?? null) ? $info['label'] : null,
            limit: $num($info['limit'] ?? null),
            usage: $num($info['usage'] ?? null) ?? 0.0,
            limitRemaining: $num($info['limit_remaining'] ?? null),
            freeTier: (bool) ($info['is_free_tier'] ?? false),
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $options = []): array
    {
        $key = $this->settings->secret(SettingKeys::AI_OPENROUTER_KEY);
        if (null === $key) {
            throw new AiNotConfiguredException('Ключ OpenRouter не задано');
        }
        try {
            $response = $this->httpClient->request($method, $this->baseUrl.$path, $options + [
                'auth_bearer' => $key,
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout * 2,
                // атрибуція OpenRouter (необов'язкова): звідки запит; ПД тут нема
                'headers' => ['HTTP-Referer' => 'https://'.$this->siteHost, 'X-Title' => $this->siteHost],
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw $this->httpError($status, $response);
            }
            $body = $response->getContent();
        } catch (HttpException $e) {
            // мережа/таймаут/TLS: без тіла, щоб у логах не було відлуння запиту
            throw new AiProviderException('OpenRouter недоступний: '.$e::class, 0, $e);
        }
        try {
            $data = json_decode($body, true, 64, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AiProviderException('OpenRouter: відповідь не JSON', 0, $e);
        }
        if (!\is_array($data)) {
            throw new AiProviderException('OpenRouter: відповідь не об\'єкт');
        }

        return $data;
    }

    private function httpError(int $status, ResponseInterface $response): AiException
    {
        $message = null;
        try {
            $data = json_decode($response->getContent(false), true);
            $message = \is_array($data) && \is_array($data['error'] ?? null) && \is_string($data['error']['message'] ?? null) ? mb_strimwidth($data['error']['message'], 0, 200, '…') : null;
        } catch (HttpException|JsonException) {
        }
        $text = \sprintf('OpenRouter %d%s', $status, null === $message ? '' : ': '.$message);

        return match (true) {
            401 === $status || 403 === $status => new AiAuthException($text, $status),
            402 === $status => new AiPaymentRequiredException($text, $status),
            429 === $status => new AiRateLimitedException($text, self::retryAfter($response)),
            $status >= 500 => new AiProviderException($text, $status),
            default => new AiException($text, $status),
        };
    }

    private static function retryAfter(ResponseInterface $response): ?int
    {
        $header = $response->getHeaders(false)['retry-after'][0] ?? null;

        return null !== $header && is_numeric($header) ? max(1, (int) $header) : null;
    }
}
