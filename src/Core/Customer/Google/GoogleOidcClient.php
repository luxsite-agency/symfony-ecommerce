<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Google;

use App\Core\Customer\Entity\Customer;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Мінімальний OpenID Connect-клієнт для Google (ADR «Вхід через Google», 13.10.2026): authorization code + PKCE (S256),
 * обмін коду на токени, перевірка id_token (iss, aud, exp/iat, nonce, підпис RS256 ключем з JWKS). JWKS кешується в
 * cache.app на app.google.jwks_ttl; невідомий kid — одне повторне завантаження (ротація ключів Google). Бібліотека не
 * потрібна: symfony/http-client + openssl; адреси кінцевих точок — параметр app.google.endpoints (у тестах — фейковий
 * сервер). Профіль з userinfo не читається: усе потрібне (sub, email, email_verified, name) є в id_token.
 */
final class GoogleOidcClient
{
    public const string SCOPE = 'openid email profile';
    /** Дозволені значення iss у id_token Google (обидва — з документації Google). */
    private const array ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];
    private const string JWKS_CACHE_KEY = 'google_oidc.jwks';
    private const int CLOCK_SKEW = 60;

    /** @param array{discovery: string, authorization: string, token: string, jwks: string} $endpoints */
    public function __construct(
        private readonly GoogleSignInConfig $config,
        #[Autowire(service: 'google_oidc.http_client')] private readonly HttpClientInterface $http,
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
        #[Autowire('%app.google.endpoints%')] private readonly array $endpoints,
        #[Autowire('%app.google.jwks_ttl%')] private readonly int $jwksTtl,
    ) {
    }

    /** @return string code_verifier (43 символи base64url) */
    public static function newCodeVerifier(): string
    {
        return self::base64url(random_bytes(32));
    }

    public static function newToken(): string
    {
        return self::base64url(random_bytes(24));
    }

    public function authorizationUrl(string $redirectUri, string $state, string $nonce, string $codeVerifier, ?string $loginHint = null): string
    {
        $params = [
            'client_id' => $this->config->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::base64url(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
        ];
        if (null !== $loginHint) {
            $params['login_hint'] = $loginHint;
        }

        return $this->endpoints['authorization'].'?'.http_build_query($params, '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * Обмін коду на id_token і його перевірка.
     *
     * @throws OidcException
     */
    public function exchange(string $code, string $redirectUri, string $codeVerifier, string $nonce): GoogleIdentity
    {
        try {
            $response = $this->http->request('POST', $this->endpoints['token'], [
                'body' => [
                    'code' => $code,
                    'client_id' => $this->config->clientId(),
                    'client_secret' => $this->config->clientSecret(),
                    'redirect_uri' => $redirectUri,
                    'grant_type' => 'authorization_code',
                    'code_verifier' => $codeVerifier,
                ],
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (HttpException $e) {
            throw new OidcException('token_request_failed', $e->getMessage());
        }
        if (200 !== $status) {
            throw new OidcException('token_rejected', \is_string($payload['error'] ?? null) ? $payload['error'] : 'HTTP '.$status);
        }
        $idToken = $payload['id_token'] ?? null;
        if (!\is_string($idToken) || '' === $idToken) {
            throw new OidcException('id_token_missing');
        }

        return $this->verifyIdToken($idToken, $nonce);
    }

    /**
     * iss, aud, exp (з люфтом 60 с), iat, nonce, alg RS256, підпис ключем JWKS з потрібним kid.
     *
     * @throws OidcException
     */
    public function verifyIdToken(string $jwt, string $nonce): GoogleIdentity
    {
        $parts = explode('.', $jwt);
        if (3 !== \count($parts)) {
            throw new OidcException('id_token_malformed');
        }
        [$header64, $payload64, $signature64] = $parts;
        $header = self::jsonDecode(self::base64urlDecode($header64));
        $claims = self::jsonDecode(self::base64urlDecode($payload64));
        $signature = self::base64urlDecode($signature64);
        if (null === $header || null === $claims || '' === $signature) {
            throw new OidcException('id_token_malformed');
        }
        if ('RS256' !== ($header['alg'] ?? null) || !\is_string($header['kid'] ?? null)) {
            throw new OidcException('id_token_alg');
        }
        $key = $this->publicKey($header['kid']);
        if (null === $key) {
            throw new OidcException('id_token_unknown_kid');
        }
        if (1 !== openssl_verify($header64.'.'.$payload64, $signature, $key, \OPENSSL_ALGO_SHA256)) {
            throw new OidcException('id_token_signature');
        }
        if (!\in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw new OidcException('id_token_iss');
        }
        $aud = $claims['aud'] ?? null;
        if (!(\is_string($aud) && hash_equals($this->config->clientId(), $aud)) && !(\is_array($aud) && \in_array($this->config->clientId(), $aud, true))) {
            throw new OidcException('id_token_aud');
        }
        $now = time();
        $exp = $claims['exp'] ?? null;
        $iat = $claims['iat'] ?? null;
        if (!\is_int($exp) || $exp + self::CLOCK_SKEW < $now) {
            throw new OidcException('id_token_expired');
        }
        if (!\is_int($iat) || $iat - self::CLOCK_SKEW > $now) {
            throw new OidcException('id_token_iat');
        }
        if (!\is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
            throw new OidcException('id_token_nonce');
        }
        $sub = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        if (!\is_string($sub) || '' === $sub || !\is_string($email) || '' === $email) {
            throw new OidcException('id_token_claims');
        }
        $name = $claims['name'] ?? null;

        return new GoogleIdentity($sub, $email, true === ($claims['email_verified'] ?? false), \is_string($name) && '' !== trim($name) ? mb_substr(trim($name), 0, Customer::NAME_MAX) : null);
    }

    /** JWKS з кешу; невідомий kid — одне повторне завантаження (ротація ключів). @return \OpenSSLAsymmetricKey|null */
    private function publicKey(string $kid): mixed
    {
        $item = $this->cache->getItem(self::JWKS_CACHE_KEY);
        /** @var array<string, array{n: string, e: string}>|null $keys */
        $keys = $item->isHit() ? $item->get() : null;
        if (!\is_array($keys) || !isset($keys[$kid])) {
            $keys = $this->fetchJwks();
            $item->set($keys)->expiresAfter($this->jwksTtl);
            $this->cache->save($item);
        }
        if (!isset($keys[$kid])) {
            return null;
        }
        $pem = self::pemFromRsa(self::base64urlDecode($keys[$kid]['n']), self::base64urlDecode($keys[$kid]['e']));
        $key = openssl_pkey_get_public($pem);

        return false === $key ? null : $key;
    }

    /**
     * «Перевірити налаштування» в адмінці: discovery-документ Google (issuer, кінцеві точки) і свіжий JWKS (кеш оновлюється).
     * endpointsMatch=false — Google оголошує інші адреси, ніж зашиті в app.google.endpoints: вхід ще працює, але розробнику
     * варто звірити параметри. Ключів клієнта тут не треба: повний вхід адміністратор перевіряє сам кнопкою на сайті.
     *
     * @return array{issuer: string, keys: int, endpointsMatch: bool}
     *
     * @throws OidcException discovery_unavailable, discovery_invalid, jwks_unavailable, jwks_empty
     */
    public function probe(): array
    {
        try {
            $doc = $this->http->request('GET', $this->endpoints['discovery'], ['timeout' => 10])->toArray();
        } catch (HttpException $e) {
            throw new OidcException('discovery_unavailable', $e->getMessage());
        }
        $issuer = $doc['issuer'] ?? null;
        if (!\is_string($issuer) || !\in_array($issuer, self::ISSUERS, true)) {
            throw new OidcException('discovery_invalid', 'issuer');
        }
        $match = true;
        foreach (['authorization_endpoint' => 'authorization', 'token_endpoint' => 'token', 'jwks_uri' => 'jwks'] as $claim => $key) {
            if (!\is_string($doc[$claim] ?? null)) {
                throw new OidcException('discovery_invalid', $claim);
            }
            $match = $match && $doc[$claim] === $this->endpoints[$key];
        }
        $keys = $this->fetchJwks();
        $item = $this->cache->getItem(self::JWKS_CACHE_KEY);
        $item->set($keys)->expiresAfter($this->jwksTtl);
        $this->cache->save($item);

        return ['issuer' => $issuer, 'keys' => \count($keys), 'endpointsMatch' => $match];
    }

    /** @return array<string, array{n: string, e: string}> kid → компоненти RSA (base64url) */
    private function fetchJwks(): array
    {
        try {
            $payload = $this->http->request('GET', $this->endpoints['jwks'], ['timeout' => 10])->toArray();
        } catch (HttpException $e) {
            throw new OidcException('jwks_unavailable', $e->getMessage());
        }
        $keys = [];
        foreach ($payload['keys'] ?? [] as $jwk) {
            if (\is_array($jwk) && 'RSA' === ($jwk['kty'] ?? null) && \is_string($jwk['kid'] ?? null) && \is_string($jwk['n'] ?? null) && \is_string($jwk['e'] ?? null)
                && (!isset($jwk['use']) || 'sig' === $jwk['use'])) {
                $keys[$jwk['kid']] = ['n' => $jwk['n'], 'e' => $jwk['e']];
            }
        }
        if ([] === $keys) {
            throw new OidcException('jwks_empty');
        }

        return $keys;
    }

    /** SubjectPublicKeyInfo (PKCS#1 RSAPublicKey усередині) з модуля й експоненти — PEM для openssl. */
    public static function pemFromRsa(string $modulus, string $exponent): string
    {
        $rsaKey = self::derSequence(self::derInteger($modulus).self::derInteger($exponent));
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"; // rsaEncryption, NULL
        $bitString = "\x03".self::derLength(\strlen($rsaKey) + 1)."\x00".$rsaKey;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(self::derSequence($algorithm.$bitString)), 64, "\n").'-----END PUBLIC KEY-----';
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ('' === $bytes || \ord($bytes[0]) >= 0x80) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::derLength(\strlen($bytes)).$bytes;
    }

    private static function derSequence(string $content): string
    {
        return "\x30".self::derLength(\strlen($content)).$content;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return \chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return \chr(0x80 | \strlen($bytes)).$bytes;
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $text): string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return false === $decoded ? '' : $decoded;
    }

    /** @return array<string, mixed>|null */
    private static function jsonDecode(string $json): ?array
    {
        try {
            $data = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }
}
