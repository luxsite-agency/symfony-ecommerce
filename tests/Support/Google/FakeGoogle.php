<?php

declare(strict_types=1);

namespace App\Tests\Support\Google;

use App\Core\Customer\Google\GoogleOidcClient;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Фейковий OIDC-провайдер Google для функціональних тестів (when@test: google_oidc.http_client → цей сервіс, кінцеві точки
 * google.test у services.yaml). Ключ RSA генерується раз на процес (kid випадковий, тож закешований у cache.app JWKS
 * попереднього прогону не підходить — шлях «невідомий kid → повторне завантаження» проходиться сам). Тест забирає з
 * Location старту state/nonce/code_challenge, видає код через grant() і йде на callback; token-endpoint перевіряє
 * client_id/secret, redirect_uri, PKCE (S256 верифікатора = challenge) — як справжній Google. Підробки (чужий aud, iss,
 * прострочений exp, інший nonce, інший ключ підпису) — через $overrides і signWithUnknownKey.
 */
final class FakeGoogle implements HttpClientInterface
{
    public const string CLIENT_ID = 'test-client-id.apps.googleusercontent.com';
    public const string CLIENT_SECRET = 'test-client-secret';
    public const string REDIRECT_URI = 'https://shop-engine.localhost/account/google/callback';

    private OpenSSLAsymmetricKey $key;
    private string $kid;
    /** @var array<string, array{claims: array<string, mixed>, challenge: ?string, unknownKey: bool}> */
    private array $codes = [];
    /** @var list<array{method: string, url: string, body: array<string, string>}> */
    public array $requests = [];
    public int $jwksRequests = 0;
    public bool $tokenEndpointDown = false;
    /** Усе лежить (503): для «Перевірити налаштування» в адмінці. */
    public bool $down = false;
    /** @var array<string, string> перекриття discovery-документа (наприклад, інший jwks_uri) */
    public array $discoveryOverrides = [];
    /** Ключі, які приймає token endpoint: за замовчуванням — як у .env.test; тест з ключами з адмінки підставляє свої. */
    public string $expectedClientId = self::CLIENT_ID;
    public string $expectedClientSecret = self::CLIENT_SECRET;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        if (false === $key) {
            throw new RuntimeException('openssl_pkey_new');
        }
        $this->key = $key;
        $this->kid = 'kid-'.bin2hex(random_bytes(6));
    }

    /**
     * Видати код для callback: claims — як у Google (sub, email, email_verified, name); nonce і code_challenge — з
     * Location старту. $overrides перекривають будь-який claim (aud, iss, exp, nonce…), $unknownKey — підпис іншим ключем.
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $overrides
     */
    public function grant(array $claims, string $nonce, ?string $codeChallenge, array $overrides = [], bool $unknownKey = false): string
    {
        $code = 'code-'.bin2hex(random_bytes(8));
        $now = time();
        $this->codes[$code] = [
            'claims' => $overrides + $claims + ['iss' => 'https://accounts.google.com', 'aud' => $this->expectedClientId, 'iat' => $now, 'exp' => $now + 3600, 'nonce' => $nonce, 'email_verified' => true],
            'challenge' => $codeChallenge,
            'unknownKey' => $unknownKey,
        ];

        return $code;
    }

    /** @param array<string, mixed> $claims */
    public function idToken(array $claims, bool $unknownKey = false): string
    {
        $key = $unknownKey ? openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]) : $this->key;
        if (false === $key) {
            throw new RuntimeException('openssl_pkey_new');
        }
        $header = GoogleOidcClient::base64url(json_encode(['alg' => 'RS256', 'kid' => $this->kid, 'typ' => 'JWT'], \JSON_THROW_ON_ERROR));
        $payload = GoogleOidcClient::base64url(json_encode($claims, \JSON_THROW_ON_ERROR));
        openssl_sign($header.'.'.$payload, $signature, $key, \OPENSSL_ALGO_SHA256);

        return $header.'.'.$payload.'.'.GoogleOidcClient::base64url($signature);
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $body = [];
        if (\is_string($options['body'] ?? null)) {
            parse_str($options['body'], $body);
        } elseif (\is_array($options['body'] ?? null)) {
            $body = $options['body'];
        }
        /** @var array<string, string> $body */
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
        $client = new MockHttpClient(fn () => $this->respond($method, $url, $body));

        return $client->request($method, $url, $options);
    }

    /** @param array<string, string> $body */
    private function respond(string $method, string $url, array $body): MockResponse
    {
        if ($this->down) {
            return new MockResponse('', ['http_code' => 503]);
        }
        if (str_ends_with($url, '/.well-known/openid-configuration')) {
            $base = 'https://google.test';
            $doc = $this->discoveryOverrides + ['issuer' => 'https://accounts.google.com', 'authorization_endpoint' => $base.'/o/oauth2/v2/auth', 'token_endpoint' => $base.'/token', 'jwks_uri' => $base.'/certs'];

            return new MockResponse(json_encode($doc, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        }
        if (str_ends_with($url, '/certs')) {
            ++$this->jwksRequests;
            $details = openssl_pkey_get_details($this->key);
            if (false === $details) {
                throw new RuntimeException('openssl_pkey_get_details');
            }

            return new MockResponse(json_encode(['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $this->kid, 'n' => GoogleOidcClient::base64url($details['rsa']['n']), 'e' => GoogleOidcClient::base64url($details['rsa']['e'])]]], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        }
        if (str_ends_with($url, '/token') && 'POST' === $method) {
            if ($this->tokenEndpointDown) {
                return new MockResponse('', ['http_code' => 503]);
            }
            $code = $body['code'] ?? '';
            $grant = $this->codes[$code] ?? null;
            unset($this->codes[$code]);
            if (null === $grant || ($body['client_id'] ?? '') !== $this->expectedClientId || ($body['client_secret'] ?? '') !== $this->expectedClientSecret
                || ($body['redirect_uri'] ?? '') !== self::REDIRECT_URI || 'authorization_code' !== ($body['grant_type'] ?? '')
                || (null !== $grant['challenge'] && GoogleOidcClient::base64url(hash('sha256', $body['code_verifier'] ?? '', true)) !== $grant['challenge'])) {
                return new MockResponse(json_encode(['error' => 'invalid_grant'], \JSON_THROW_ON_ERROR), ['http_code' => 400, 'response_headers' => ['content-type' => 'application/json']]);
            }

            return new MockResponse(json_encode(['access_token' => 'at-'.bin2hex(random_bytes(4)), 'token_type' => 'Bearer', 'expires_in' => 3599, 'id_token' => $this->idToken($grant['claims'], $grant['unknownKey'])], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        }

        return new MockResponse('not found', ['http_code' => 404]);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return (new MockHttpClient())->stream($responses, $timeout);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return $this;
    }
}
