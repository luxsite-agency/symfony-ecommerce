<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Auth;

use App\Core\AdminApi\AdminApiTokens;
use App\Core\AdminApi\Entity\AdminApiToken;
use DateTimeImmutable;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Bearer-автентифікація firewall admin_api (E15 PR-2): `Authorization: Bearer shp_…` → хеш → AdminApiToken → власник.
 * Без сесії (stateless). Невдалі спроби лічаться з IP (limiter admin_api_auth_ip) і йдуть у security_audit без значення
 * токена (лише префікс, якщо формат наш). Активність власника перевіряє user_checker firewall (ActiveUserChecker).
 * Відповіді за RFC 6750: 401 + WWW-Authenticate: Bearer realm, error, error_description; 429 + Retry-After.
 */
#[WithMonologChannel('security_audit')]
final class ApiTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const string REALM = 'shop-admin-api';

    public function __construct(
        private readonly AdminApiTokens $tokens,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.admin_api_auth_ip')] private readonly RateLimiterFactoryInterface $authLimiter,
    ) {
    }

    public function supports(Request $request): bool
    {
        return null !== self::bearer($request);
    }

    /** Схема за RFC 9110 регістронезалежна: `bearer shp_…` — теж токен, а не «без заголовка». */
    private static function bearer(Request $request): ?string
    {
        return 1 === preg_match('/^Bearer\s+(\S+)\s*$/i', (string) $request->headers->get('Authorization', ''), $m) ? $m[1] : null;
    }

    public function authenticate(Request $request): Passport
    {
        $ip = $request->getClientIp() ?? 'anon';
        $limiter = $this->authLimiter->create($ip);
        $limit = $limiter->consume(0);
        if (!$limit->isAccepted()) {
            throw ApiTokenException::throttled($limit->getRetryAfter()->getTimestamp() - time());
        }
        $plain = self::bearer($request) ?? '';
        $token = $this->tokens->findByPlain($plain);
        $prefix = AdminApiTokens::looksLikeToken($plain) ? substr($plain, 0, AdminApiToken::PREFIX_LENGTH) : null;
        if (null === $token) {
            throw $this->failure($limiter->consume(), $ip, 'unknown', $prefix, null);
        }
        if ($token->isRevoked()) {
            throw $this->failure($limiter->consume(), $ip, 'revoked', $prefix, $token, 'Токен відкликано', 'Token revoked');
        }
        if ($token->isExpired()) {
            throw $this->failure($limiter->consume(), $ip, 'expired', $prefix, $token, 'Строк дії токена минув', 'Token expired');
        }
        $allowlist = $token->getIpAllowlist();
        if (null !== $allowlist && !IpUtils::checkIp($ip, $allowlist)) {
            throw $this->failure($limiter->consume(), $ip, 'ip_not_allowed', $prefix, $token);
        }
        $user = $token->getUser();

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user), [new ApiTokenBadge($token)]);
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $token = new PostAuthenticationToken($passport->getUser(), $firewallName, $passport->getUser()->getRoles());
        $badge = $passport->getBadge(ApiTokenBadge::class);
        if ($badge instanceof ApiTokenBadge) {
            $token->setAttribute(ApiTokenContext::ATTRIBUTE, $badge->token);
        }

        return $token;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $api = $token->hasAttribute(ApiTokenContext::ATTRIBUTE) ? $token->getAttribute(ApiTokenContext::ATTRIBUTE) : null;
        if ($api instanceof AdminApiToken) {
            $this->tokens->touch($api, $request->getClientIp(), new DateTimeImmutable());
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof ApiTokenException && null !== $exception->getRetryAfter()) {
            return new JsonResponse(['error' => $exception->getError(), 'error_description' => $exception->getMessageKey()], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $exception->getRetryAfter()]);
        }
        // user_checker (обліковий запис вимкнено) та інші винятки — теж 401 invalid_token, без подробиць назовні
        $description = $exception instanceof ApiTokenException ? $exception->getMessageKey() : 'Недійсний токен';
        $header = $exception instanceof ApiTokenException ? $exception->getHeaderDescription() : 'Invalid token';
        if (!$exception instanceof ApiTokenException) {
            $this->logger->warning('api_token.auth_failed', ['reason' => 'user_check', 'ip' => self::anonymize($request->getClientIp()), 'message' => $exception->getMessageKey()]);
        }

        return self::unauthorized('invalid_token', $description, $header);
    }

    /** Без заголовка Authorization: 401 з викликом Bearer (RFC 6750 §3). */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::unauthorized(null, 'Потрібен персональний API-токен: Authorization: Bearer shp_…', 'Bearer token required');
    }

    private function failure(RateLimit $limit, string $ip, string $reason, ?string $prefix, ?AdminApiToken $token, string $description = 'Недійсний токен', string $headerDescription = 'Invalid token'): ApiTokenException
    {
        $this->logger->warning('api_token.auth_failed', ['reason' => $reason, 'ip' => self::anonymize($ip), 'prefix' => $prefix, 'token_id' => $token?->getId(), 'user_id' => $token?->getUser()->getId()]);
        if (!$limit->isAccepted()) {
            return ApiTokenException::throttled($limit->getRetryAfter()->getTimestamp() - time());
        }

        return ApiTokenException::invalid($description, $headerDescription);
    }

    /** Заголовок — лише ASCII (RFC 6750 §3, рев'ю C #73 L1): український текст іде в JSON-тілі. */
    private static function unauthorized(?string $error, string $description, string $headerDescription): JsonResponse
    {
        $challenge = \sprintf('Bearer realm="%s"', self::REALM);
        if (null !== $error) {
            $challenge .= \sprintf(', error="%s", error_description="%s"', $error, preg_replace('/[^\x20-\x21\x23-\x5B\x5D-\x7E]/', '', $headerDescription));
        }

        return new JsonResponse(['error' => $error ?? 'unauthorized', 'error_description' => $description], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => $challenge]);
    }

    private static function anonymize(?string $ip): ?string
    {
        return null === $ip || '' === $ip ? null : IpUtils::anonymize($ip);
    }
}
