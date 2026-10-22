<?php

declare(strict_types=1);

namespace App\Tests\Functional\AdminApi;

use App\Core\AdminApi\AdminApiTokens;
use App\Core\AdminApi\ApiScope;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Захист API і MCP (E15 PR-6): тіло понад app.admin_api_max_body_bytes → 413 ще до автентифікації; журнал admin_api —
 * лише метод, маршрут/інструмент, token_id, статус (ні query, ні аргументів, ні тіла); відкликання токена й деактивація
 * власника діють з наступного запиту (БД щоразу, кешу нема); Cache-Control: no-store на кожну відповідь.
 */
final class ApiHardeningTest extends WebTestCase
{
    use CatalogFixtures;

    private const string SECRET = 'таємний-запит-XYZ-7781';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private TestHandler $log;
    private string $adminHost;
    private int $maxBody;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $logger = $container->get('monolog.logger.admin_api');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($this->log = new TestHandler());
        /** @var string $adminHost */
        $adminHost = $container->getParameter('admin_host');
        $this->adminHost = $adminHost;
        /** @var int $maxBody */
        $maxBody = $container->getParameter('app.admin_api_max_body_bytes');
        $this->maxBody = $maxBody;
        self::seedCatalog($this->em);
    }

    /** @return array{string, User, int} plain, власник, id токена */
    private function token(string $email = 'olena@example.test'): array
    {
        $user = new User($email, 'Олена', UserRole::Manager);
        $this->em->persist($user);
        $this->em->flush();
        $issued = static::getContainer()->get(AdminApiTokens::class)->issue($user, 'cc', [ApiScope::CatalogRead, ApiScope::ContentWrite], 30, $user);

        return [$issued->plain, $user, (int) $issued->token->getId()];
    }

    /** @param array<string, string> $server */
    private function request(string $method, string $path, ?string $bearer, ?string $content = null, array $server = []): int
    {
        if (null !== $bearer) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
        }
        $this->client->request($method, 'https://'.$this->adminHost.$path, server: ['CONTENT_TYPE' => 'application/json'] + $server, content: $content);

        return $this->client->getResponse()->getStatusCode();
    }

    /** @param array<string, mixed> $arguments */
    private function rpc(string $tool, array $arguments = []): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]], \JSON_THROW_ON_ERROR);
    }

    public function testOversizedBodyIs413BeforeAuthentication(): void
    {
        $big = '{"pad":"'.str_repeat('a', $this->maxBody).'"}';
        self::assertSame(413, $this->request('POST', '/mcp', null, $big), 'без токена: 413, а не 401 — тіло не читається далі');
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['error' => 'payload_too_large', 'limit' => $this->maxBody], array_intersect_key($json, array_flip(['error', 'limit'])));
        self::assertSame(413, $this->request('POST', '/api/admin/v1/changesets', null, $big));
        // Content-Length більший за тіло — теж 413 (nginx звіряє заголовок, PHP — і заголовок, і фактичний розмір)
        self::assertSame(413, $this->request('POST', '/mcp', null, '{}', ['HTTP_CONTENT_LENGTH' => (string) ($this->maxBody + 1)]));
        // у межах — звичайний хід: без токена 401
        self::assertSame(401, $this->request('POST', '/mcp', null, '{"jsonrpc":"2.0","id":1,"method":"ping"}'));
        // поза API страж не діє
        self::assertNotSame(413, $this->request('POST', '/login', null, $big));
    }

    public function testAccessLogKeepsMethodToolTokenStatusOnly(): void
    {
        [$bearer, , $tokenId] = $this->token();
        $this->request('POST', '/mcp', $bearer, $this->rpc('search_products', ['q' => self::SECRET]));
        self::assertResponseIsSuccessful();
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'), 'відповідь з токеном не кешується');
        $this->request('GET', '/api/admin/v1/products?q='.rawurlencode(self::SECRET), $bearer);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        $this->request('POST', '/mcp', 'shp_'.str_repeat('x', 43), $this->rpc('search_products', ['q' => self::SECRET]));
        self::assertResponseStatusCodeSame(401);
        $this->request('POST', '/mcp', $bearer, '{"jsonrpc":"2.0","method":"notifications/initialized"}');
        self::assertResponseStatusCodeSame(202);

        $records = $this->log->getRecords();
        self::assertCount(4, $records, 'один запис на запит');
        $keys = ['method', 'route', 'rpc', 'tool', 'token_id', 'status', 'ms'];
        foreach ($records as $record) {
            self::assertSame('api.request', $record->message);
            self::assertSame($keys, array_keys($record->context), 'жодного іншого поля: ні query, ні аргументів, ні тіла, ні IP');
            self::assertStringNotContainsString(self::SECRET, json_encode($record->toArray(), \JSON_UNESCAPED_UNICODE) ?: '');
        }
        $rows = array_map(static fn ($r): array => array_diff_key($r->context, ['ms' => 1]), $records);
        self::assertSame([
            ['method' => 'POST', 'route' => 'admin_mcp', 'rpc' => 'tools/call', 'tool' => 'search_products', 'token_id' => $tokenId, 'status' => 200],
            ['method' => 'GET', 'route' => 'admin_api_catalog_products', 'rpc' => null, 'tool' => null, 'token_id' => $tokenId, 'status' => 200],
            ['method' => 'POST', 'route' => 'admin_mcp', 'rpc' => null, 'tool' => null, 'token_id' => null, 'status' => 401],
            ['method' => 'POST', 'route' => 'admin_mcp', 'rpc' => 'notifications/initialized', 'tool' => null, 'token_id' => $tokenId, 'status' => 202],
        ], $rows);
        self::assertIsInt($records[0]->context['ms']);
    }

    public function testRevocationAndDeactivationTakeEffectOnNextRequest(): void
    {
        [$bearer, $user, $tokenId] = $this->token();
        [$bearer2, $user2] = $this->token('ihor@example.test');
        $ping = '{"jsonrpc":"2.0","id":1,"method":"ping"}';
        self::assertSame(200, $this->request('POST', '/mcp', $bearer, $ping));
        self::assertSame(200, $this->request('GET', '/api/admin/v1/me', $bearer));

        // відкликання (адмінка/сервіс пише revoked_at) — наступний запит уже 401: токен читається з БД щоразу, без кешу
        $this->em->getConnection()->update('admin_api_token', ['revoked_at' => date('Y-m-d H:i:s'), 'revoked_by_id' => $user->getId()], ['id' => $tokenId]);
        self::assertSame(401, $this->request('POST', '/mcp', $bearer, $ping));
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['error' => 'invalid_token', 'error_description' => 'Токен відкликано'], $json);
        self::assertStringContainsString('error="invalid_token"', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));
        self::assertSame(401, $this->request('GET', '/api/admin/v1/me', $bearer));

        // деактивація власника — те саме для всіх його токенів, без відкликання кожного
        self::assertSame(200, $this->request('POST', '/mcp', $bearer2, $ping));
        $this->em->getConnection()->update('security_user', ['active' => 0], ['id' => $user2->getId()]);
        self::assertSame(401, $this->request('POST', '/mcp', $bearer2, $ping));
        self::assertSame(401, $this->request('GET', '/api/admin/v1/me', $bearer2));
    }
}
