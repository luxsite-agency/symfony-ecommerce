<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\AdminApi;

use App\Core\AdminApi\AdminApiTokens;
use App\Core\AdminApi\ApiScope;
use App\Core\AdminApi\Auth\ApiRateLimitListener;
use App\Core\AdminApi\Auth\ApiTokenAuthenticator;
use App\Core\AdminApi\Auth\ApiTokenContext;
use App\Core\AdminApi\Auth\ApiTokenException;
use App\Core\AdminApi\Auth\ApiWriteLimiter;
use App\Core\AdminApi\Entity\AdminApiToken;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Ліміти API (E15 PR-2/6) на лімітерах у пам'яті: невдалі автентифікації з IP → 429 з Retry-After; запити на токен → 429;
 * спроби створити пакет на токен (ApiWriteLimiter) → Retry-After.
 * У тестовому env ліміти величезні (services.yaml when@test), тут — малі цифри на власній фабриці.
 */
final class ApiRateLimitsTest extends KernelTestCase
{
    public function testFailedAuthenticationsFromIpAreThrottled(): void
    {
        self::bootKernel();
        $tokens = self::getContainer()->get(AdminApiTokens::class);
        $limiter = new RateLimiterFactory(['id' => 'auth', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '1 minute'], new InMemoryStorage());
        $authenticator = new ApiTokenAuthenticator($tokens, new NullLogger(), $limiter);
        $request = Request::create('https://admin.example.test/api/admin/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer shp_wrong', 'REMOTE_ADDR' => '203.0.113.7']);

        $errors = [];
        for ($i = 0; $i < 3; ++$i) {
            try {
                $authenticator->authenticate($request);
                self::fail('невірний токен має кидати виняток');
            } catch (ApiTokenException $e) {
                $errors[] = $e->getError();
                $response = $authenticator->onAuthenticationFailure($request, $e);
            }
        }
        self::assertSame(['invalid_token', 'invalid_token', 'too_many_requests'], $errors);
        self::assertSame(429, $response->getStatusCode());
        self::assertGreaterThanOrEqual(1, (int) $response->headers->get('Retry-After'));
        self::assertStringContainsString('too_many_requests', (string) $response->getContent());

        // інший IP — свій лічильник
        $other = Request::create('https://admin.example.test/api/admin/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer shp_wrong', 'REMOTE_ADDR' => '198.51.100.9']);
        try {
            $authenticator->authenticate($other);
        } catch (ApiTokenException $e) {
            self::assertSame('invalid_token', $e->getError());
        }
    }

    public function testRequestsPerTokenAreThrottled(): void
    {
        $user = new User('m@example.test', 'M', UserRole::Manager);
        $api = new AdminApiToken($user, 't', [ApiScope::CatalogRead], str_repeat('b', 64), 'shp_abcdefgh', new DateTimeImmutable('+1 day'));
        $ref = new ReflectionProperty($api, 'id');
        $ref->setValue($api, 42);
        $token = new PostAuthenticationToken($user, 'admin_api', ['ROLE_MANAGER']);
        $token->setAttribute(ApiTokenContext::ATTRIBUTE, $api);
        $storage = new TokenStorage();
        $storage->setToken($token);
        $listener = new ApiRateLimitListener(new ApiTokenContext($storage), new RateLimiterFactory(['id' => 'tok', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '1 minute'], new InMemoryStorage()));
        $kernel = $this->createStub(HttpKernelInterface::class);

        $statuses = [];
        for ($i = 0; $i < 3; ++$i) {
            $event = new RequestEvent($kernel, Request::create('/api/admin/v1/me'), HttpKernelInterface::MAIN_REQUEST);
            $listener($event);
            $statuses[] = $event->getResponse()?->getStatusCode();
        }
        self::assertSame([null, null, 429], $statuses);
        self::assertTrue($event->getResponse()?->headers->has('Retry-After'));

        // без API-токена (сесія адмінки, сайт) лімітер не чіпає
        $storage->setToken(null);
        $event = new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST);
        $listener($event);
        self::assertNull($event->getResponse());
    }

    /** E15 PR-6: спроби створити пакет рахуються на токен окремо від загального ліміту; інший токен — свій лічильник. */
    public function testWriteAttemptsPerTokenAreThrottled(): void
    {
        $user = new User('m@example.test', 'M', UserRole::Manager);
        $mine = new AdminApiToken($user, 't', [ApiScope::ContentWrite], str_repeat('b', 64), 'shp_abcdefgh', new DateTimeImmutable('+1 day'));
        $other = new AdminApiToken($user, 'u', [ApiScope::ContentWrite], str_repeat('c', 64), 'shp_ijklmnop', new DateTimeImmutable('+1 day'));
        $ref = new ReflectionProperty(AdminApiToken::class, 'id');
        $ref->setValue($mine, 42);
        $ref->setValue($other, 43);
        $limiter = new ApiWriteLimiter(new RateLimiterFactory(['id' => 'w', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '1 minute'], new InMemoryStorage()));

        $results = [];
        for ($i = 0; $i < 3; ++$i) {
            $results[] = $limiter->consume($mine);
        }
        self::assertSame([null, null], \array_slice($results, 0, 2));
        self::assertGreaterThanOrEqual(1, $results[2], 'третя спроба за хвилину — Retry-After');
        self::assertNull($limiter->consume($other), 'інший токен — свій лічильник');
    }
}
