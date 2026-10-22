<?php

declare(strict_types=1);

namespace App\Tests\Functional\AdminApi;

use App\Core\AdminApi\AdminApiTokens;
use App\Core\AdminApi\ApiScope;
use App\Core\AdminApi\Entity\AdminApiToken;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Firewall admin_api (E15 PR-2): Bearer shp_… → /api/admin/v1/me; 401 (RFC 6750) для невідомого/відкликаного/простроченого
 * токена й вимкненого користувача; 403 insufficient_scope; без сесії й cookie; лише хост адмінки; сесія адмінки на API не діє.
 */
final class ApiTokenAuthTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AdminApiTokens $tokens;
    private TestHandler $security;
    private string $adminHost;
    private string $siteHost;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->tokens = $container->get(AdminApiTokens::class);
        $logger = $container->get('monolog.logger.security_audit');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($this->security = new TestHandler());
        /** @var string $adminHost */
        $adminHost = $container->getParameter('admin_host');
        /** @var string $siteHost */
        $siteHost = $container->getParameter('site_host');
        $this->adminHost = $adminHost;
        $this->siteHost = $siteHost;
    }

    public function testMeWithValidTokenIsStateless(): void
    {
        $user = $this->user('olena@example.test', UserRole::Manager);
        $issued = $this->tokens->issue($user, 'Claude Code', [ApiScope::CatalogRead, ApiScope::ContentWrite], 30, $user);

        $this->api('/api/admin/v1/me', $issued->plain);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['id' => $user->getId(), 'name' => 'Олена', 'role' => 'ROLE_MANAGER'], $json['user']);
        self::assertSame($issued->token->getId(), $json['token']['id']);
        self::assertSame(['catalog:read', 'content:write'], $json['scopes']);
        self::assertSame(substr($issued->plain, 0, 12), $json['token']['prefix']);
        self::assertStringNotContainsString($issued->plain, (string) $this->client->getResponse()->getContent(), 'значення токена назад не віддається');
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'), 'stateless: без сесії');

        // слід використання
        $this->em->clear();
        $fresh = $this->em->find(AdminApiToken::class, $issued->token->getId());
        self::assertNotNull($fresh);
        self::assertNotNull($fresh->getLastUsedAt());
        self::assertStringEndsWith('.0', (string) $fresh->getLastUsedIp());
    }

    public function testUnauthorizedResponsesFollowRfc6750(): void
    {
        // усі сутності — до першого запиту (з disableReboot кожен запит скидає EntityManager), зміни стану далі — SQL-ом
        $user = $this->user('olena@example.test', UserRole::Manager);
        $revoked = $this->tokens->issue($user, 'old', [ApiScope::CatalogRead], 30, $user);
        $this->tokens->revoke($revoked->token, $user);
        $expired = $this->tokens->issue($user, 'expired', [ApiScope::CatalogRead], 1, $user);
        $this->em->getConnection()->update('admin_api_token', ['expires_at' => '2020-01-01 00:00:00'], ['id' => $expired->token->getId()]);
        $disabled = $this->user('off@example.test', UserRole::Manager);
        $disabledToken = $this->tokens->issue($disabled, 't', [ApiScope::CatalogRead], 30, $disabled);
        $this->em->getConnection()->update('security_user', ['active' => 0], ['id' => $disabled->getId()]);
        $this->em->clear();

        // без заголовка — виклик без error
        $this->client->request('GET', 'https://'.$this->adminHost.'/api/admin/v1/me');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('Bearer realm="shop-admin-api"', $this->client->getResponse()->headers->get('WWW-Authenticate'));
        self::assertSame('unauthorized', json_decode((string) $this->client->getResponse()->getContent(), true)['error']);

        // невідомий токен
        $this->api('/api/admin/v1/me', 'shp_'.str_repeat('Z', 43));
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('error="invalid_token"', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));
        self::assertMatchesRegularExpression('/^[\x20-\x7E]+$/', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'), 'заголовок — лише ASCII (RFC 6750 §3)');
        self::assertSame('Недійсний токен', json_decode((string) $this->client->getResponse()->getContent(), true)['error_description'], 'український текст — у тілі');
        self::assertSame('invalid_token', json_decode((string) $this->client->getResponse()->getContent(), true)['error']);
        self::assertTrue($this->security->hasWarningThatContains('api_token.auth_failed'));
        $failed = array_values(array_filter($this->security->getRecords(), static fn ($r): bool => 'api_token.auth_failed' === $r->message));
        self::assertSame('unknown', $failed[0]->context['reason']);
        self::assertSame('shp_ZZZZZZZZ', $failed[0]->context['prefix']);

        // не наш формат — без запиту в БД, той самий 401
        $this->api('/api/admin/v1/me', 'some-random-string');
        self::assertResponseStatusCodeSame(401);

        // відкликаний
        $this->api('/api/admin/v1/me', $revoked->plain);
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('відкликано', (string) json_decode((string) $this->client->getResponse()->getContent(), true)['error_description']);
        self::assertStringContainsString('error_description="Token revoked"', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));

        // схема регістронезалежна (RFC 9110): `bearer` малими — той самий шлях, не «без заголовка»
        $this->client->request('GET', 'https://'.$this->adminHost.'/api/admin/v1/me', server: ['HTTP_AUTHORIZATION' => 'bearer '.$revoked->plain]);
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('error="invalid_token"', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));

        // прострочений
        $this->api('/api/admin/v1/me', $expired->plain);
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('минув', (string) json_decode((string) $this->client->getResponse()->getContent(), true)['error_description']);

        // вимкнений користувач — токен чинний, але user_checker не пускає
        $this->api('/api/admin/v1/me', $disabledToken->plain);
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('error="invalid_token"', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));
        $reasons = array_map(static fn ($r): mixed => $r->context['reason'] ?? null, array_filter($this->security->getRecords(), static fn ($r): bool => 'api_token.auth_failed' === $r->message));
        self::assertContains('user_check', $reasons, 'вимкнений обліковий запис — у security_audit');
        self::assertContains('revoked', $reasons);
        self::assertContains('expired', $reasons);
    }

    public function testScopesGive403WithInsufficientScope(): void
    {
        $user = $this->user('olena@example.test', UserRole::Manager);
        $issued = $this->tokens->issue($user, 'read-only', [ApiScope::CatalogRead], 30, $user);

        $this->api('/api/admin/v1/_test/catalog', $issued->plain);
        self::assertResponseIsSuccessful();

        $this->api('/api/admin/v1/_test/prices', $issued->plain);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Bearer realm="shop-admin-api", error="insufficient_scope", scope="prices:write"', $this->client->getResponse()->headers->get('WWW-Authenticate'));
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['insufficient_scope', 'prices:write'], [$json['error'], $json['scope']]);
    }

    public function testApiOnlyOnAdminHostAndOnlyWithToken(): void
    {
        $user = $this->user('olena@example.test', UserRole::Manager);
        $issued = $this->tokens->issue($user, 't', [ApiScope::CatalogRead], 30, $user);

        $this->client->request('GET', 'https://'.$this->siteHost.'/api/admin/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$issued->plain]);
        self::assertResponseStatusCodeSame(404, 'на хості сайту маршруту нема');

        // токен не відкриває сторінки адмінки (firewall admin — лише сесія)
        $this->client->request('GET', 'https://'.$this->adminHost.'/product', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$issued->plain]);
        self::assertResponseRedirects('https://'.$this->adminHost.'/login');

        // і навпаки: сесія адмінки (cookie) на API не діє — firewall admin_api stateless
        $this->client->loginUser($user, 'admin');
        $this->client->request('GET', 'https://'.$this->adminHost.'/product');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', 'https://'.$this->adminHost.'/api/admin/v1/me');
        self::assertResponseStatusCodeSame(401);
    }

    private function api(string $path, string $bearer): void
    {
        $this->client->request('GET', 'https://'.$this->adminHost.$path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]);
    }

    private function user(string $email, UserRole $role): User
    {
        $user = new User($email, 'Олена', $role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
