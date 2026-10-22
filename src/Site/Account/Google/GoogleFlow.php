<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Google;

use App\Core\Customer\Google\GoogleOidcClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Стан одного проходу через Google у сесії: state (звіряється з ?state= у callback), nonce (звіряється з id_token),
 * code_verifier (PKCE), режим (login — вхід/реєстрація; link — прив'язка до поточного покупця з id), куди повернутись,
 * мова. Один прохід за раз, живе 10 хв, забирається з сесії одразу в callback (повторний callback з тим самим state —
 * «недійсний»). Ліміт `google_sign_in_ip` — на старт і callback разом.
 * redirect_uri завжди для мови за замовчуванням (/account/google/callback), щоб у Google Cloud Console був один URI на
 * хост (docs/google-sign-in.md); хост — з поточного запиту (у prod — лише trusted_hosts).
 */
final class GoogleFlow
{
    public const string SESSION_KEY = 'google_sign_in';
    public const string MODE_LOGIN = 'login';
    public const string MODE_LINK = 'link';
    private const int TTL = 600;

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        #[Autowire(service: 'limiter.google_sign_in_ip')] private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
    ) {
    }

    public function allow(Request $request): bool
    {
        return $this->limiter->create($request->getClientIp() ?? 'anon')->consume()->isAccepted();
    }

    /**
     * @return array{state: string, nonce: string, verifier: string}
     */
    public function begin(SessionInterface $session, string $mode, ?string $next, string $locale, ?int $customerId = null): array
    {
        $flow = [
            'state' => GoogleOidcClient::newToken(),
            'nonce' => GoogleOidcClient::newToken(),
            'verifier' => GoogleOidcClient::newCodeVerifier(),
            'mode' => $mode,
            'next' => $next,
            'locale' => $locale,
            'customer' => $customerId,
            'at' => time(),
        ];
        $session->set(self::SESSION_KEY, $flow);

        return $flow;
    }

    /** Режим незабраного проходу (для supports() автентифікатора), без видалення. */
    public function pendingMode(Request $request): ?string
    {
        if (!$request->hasPreviousSession()) {
            return null;
        }
        $flow = $request->getSession()->get(self::SESSION_KEY);

        return \is_array($flow) && \is_string($flow['mode'] ?? null) ? $flow['mode'] : null;
    }

    /**
     * Забрати прохід для callback: state має збігтись (hash_equals), прохід не старший за 10 хв. Прохід видаляється
     * за будь-якого результату — один callback на один старт.
     *
     * @return array{state: string, nonce: string, verifier: string, mode: string, next: ?string, locale: string, customer: ?int}|null
     */
    public function take(Request $request, mixed $state): ?array
    {
        if (!$request->hasPreviousSession()) {
            return null;
        }
        $session = $request->getSession();
        $flow = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);
        if (!\is_array($flow) || !\is_string($state) || '' === $state || !\is_string($flow['state'] ?? null) || !hash_equals($flow['state'], $state)) {
            return null;
        }
        if (!\is_int($flow['at'] ?? null) || $flow['at'] + self::TTL < time()) {
            return null;
        }
        $nonce = $flow['nonce'] ?? null;
        $verifier = $flow['verifier'] ?? null;
        $mode = $flow['mode'] ?? null;
        $next = $flow['next'] ?? null;
        $locale = $flow['locale'] ?? null;
        $customer = $flow['customer'] ?? null;
        if (!\is_string($nonce) || !\is_string($verifier) || !\is_string($mode) || !\is_string($locale) || (null !== $next && !\is_string($next)) || (null !== $customer && !\is_int($customer))) {
            return null;
        }

        return ['state' => $flow['state'], 'nonce' => $nonce, 'verifier' => $verifier, 'mode' => $mode, 'next' => $next, 'locale' => $locale, 'customer' => $customer];
    }

    public function redirectUri(): string
    {
        return $this->urls->generate('site_account_google_callback.'.$this->defaultLocale, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** Адреса після входу: збережений відносний шлях або кабінет мовою, з якої починали. */
    public function target(?string $next, string $locale): string
    {
        return ReturnPath::sanitize($next) ?? $this->urls->generate('site_account', ['_locale' => $locale]);
    }
}
