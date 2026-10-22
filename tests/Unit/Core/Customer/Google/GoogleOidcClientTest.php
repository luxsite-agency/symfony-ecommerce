<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Customer\Google;

use App\Core\Customer\Google\GoogleOidcClient;
use PHPUnit\Framework\TestCase;

/** DER/PEM з компонентів JWK (n, e) — ключ, яким openssl перевіряє підпис, той самий, що згенерував його. */
final class GoogleOidcClientTest extends TestCase
{
    public function testPemFromRsaRoundTrip(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertNotFalse($details);
        $pem = GoogleOidcClient::pemFromRsa($details['rsa']['n'], $details['rsa']['e']);
        self::assertSame(trim($details['key']), trim($pem), 'SubjectPublicKeyInfo збігається з тим, що віддає openssl');
        openssl_sign('payload', $signature, $key, \OPENSSL_ALGO_SHA256);
        $public = openssl_pkey_get_public($pem);
        self::assertNotFalse($public);
        self::assertSame(1, openssl_verify('payload', $signature, $public, \OPENSSL_ALGO_SHA256));
    }

    public function testCodeVerifierAndTokensAreBase64Url(): void
    {
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', GoogleOidcClient::newCodeVerifier());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', GoogleOidcClient::newToken());
        self::assertNotSame(GoogleOidcClient::newToken(), GoogleOidcClient::newToken());
    }
}
