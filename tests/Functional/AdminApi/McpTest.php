<?php

declare(strict_types=1);

namespace App\Tests\Functional\AdminApi;

use App\Core\AdminApi\AdminApiTokens;
use App\Core\AdminApi\ApiScope;
use App\Core\AdminApi\Mcp\McpController;
use App\Core\AdminApi\Mcp\McpTools;
use App\Core\Changeset\Entity\Changeset;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * MCP /mcp (E15 PR-5): JSON-RPC поверх POST під firewall admin_api — 401/405/403 Origin, initialize/ping/tools/list,
 * інструменти читання з untrusted-обгорткою, пакети (propose → url в адмінці, помилки як isError, скоуп за складом, чужий — не існує).
 */
final class McpTest extends WebTestCase
{
    use CatalogFixtures;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminHost;
    /** @var array{doorsId: int, handlesId: int, productId: int, variantId: int, pageId: int, postId: int} */
    private array $ids;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        /** @var string $adminHost */
        $adminHost = $container->getParameter('admin_host');
        $this->adminHost = $adminHost;
        $this->ids = self::seedCatalog($this->em);
    }

    /**
     * @param list<ApiScope> $scopes
     *
     * @return array{string, User}
     */
    private function token(array $scopes = [ApiScope::CatalogRead], string $email = 'olena@example.test', UserRole $role = UserRole::Manager): array
    {
        $user = new User($email, 'Олена', $role);
        $this->em->persist($user);
        $this->em->flush();

        return [static::getContainer()->get(AdminApiTokens::class)->issue($user, 'cc', $scopes, 30, $user)->plain, $user];
    }

    /**
     * @param array<mixed>|string|null $body
     * @param array<string, string>    $headers
     *
     * @return array<string, mixed>
     */
    private function rpc(?string $bearer, array|string|null $body, array $headers = [], string $method = 'POST', ?string $host = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'] + $headers;
        if (null !== $bearer) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
        }
        $this->client->request($method, 'https://'.($host ?? $this->adminHost).'/mcp', server: $server, content: \is_array($body) ? json_encode($body, \JSON_THROW_ON_ERROR) : $body);
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($json) ? $json : [];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> result інструмента (content, isError, structuredContent)
     */
    private function tool(string $bearer, string $name, array $arguments = [], int $id = 7): array
    {
        $json = $this->rpc($bearer, ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $arguments]]);
        self::assertResponseIsSuccessful();
        self::assertSame($id, $json['id'] ?? null);
        self::assertArrayHasKey('result', $json, 'tools/call відповідає result, помилки інструмента — в isError');

        return $json['result'];
    }

    /** Живий дизайн (20.10.2026): theme_* у tools/list, скоуп theme:write перевіряється в інструменті, запис і читання — той самий ThemeService, що REST. */
    public function testThemeToolsNeedScopeAndWriteLiveFiles(): void
    {
        /** @var string $liveDir */
        $liveDir = static::getContainer()->getParameter('app.theme_live_dir');
        (new Filesystem())->remove($liveDir);
        [$reader] = $this->token([ApiScope::CatalogRead]);
        $names = array_column($this->rpc($reader, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['result']['tools'], 'name');
        foreach (['theme_list', 'theme_get', 'theme_put', 'theme_delete', 'theme_revert'] as $tool) {
            self::assertContains($tool, $names);
        }
        $result = $this->tool($reader, 'theme_list');
        self::assertTrue($result['isError']);
        self::assertSame(['insufficient_scope', 'theme:write'], [$result['structuredContent']['error'], $result['structuredContent']['scope']]);

        [$designer] = $this->token([ApiScope::ThemeWrite], 'designer@example.test');
        $result = $this->tool($designer, 'theme_list');
        self::assertArrayNotHasKey('isError', $result);
        self::assertContains('site/home.html.twig', array_column($result['structuredContent']['files'], 'path'));

        $result = $this->tool($designer, 'theme_put', ['path' => 'theme.css', 'content' => '.mcp-live{color:red}']);
        self::assertArrayNotHasKey('isError', $result, json_encode($result));
        self::assertSame('live', $result['structuredContent']['source']);
        self::assertSame('.mcp-live{color:red}', file_get_contents($liveDir.'/public/theme.css'));
        $result = $this->tool($designer, 'theme_get', ['path' => 'theme.css']);
        self::assertSame('.mcp-live{color:red}', $result['structuredContent']['content']);

        $result = $this->tool($designer, 'theme_put', ['path' => 'site/home.html.twig', 'content' => "{{ source('site/base.html.twig') }}"]);
        self::assertTrue($result['isError']);
        self::assertSame(['validation_failed', 422], [$result['structuredContent']['error'], $result['structuredContent']['status']]);
        self::assertStringContainsString('source()', $result['content'][0]['text']);

        $result = $this->tool($designer, 'theme_delete', ['path' => 'theme.css']);
        self::assertArrayNotHasKey('isError', $result);
        self::assertFileDoesNotExist($liveDir.'/public/theme.css');
        $result = $this->tool($designer, 'theme_revert', ['path' => 'theme.css', 'version' => $result['structuredContent']['history'][0]]);
        self::assertArrayNotHasKey('isError', $result, json_encode($result));
        self::assertSame('.mcp-live{color:red}', file_get_contents($liveDir.'/public/theme.css'));
        (new Filesystem())->remove($liveDir);
    }

    /** @return array<string, mixed> */
    private function contentPackage(): array
    {
        return ['title' => 'Опис ML-14', 'operations' => [
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->ids['productId'], 'locale' => 'uk', 'fields' => ['short_description' => 'Вхідні сталеві двері'], 'expect' => ['short_description' => 'Сталеві двері']],
        ]];
    }

    /** @return array<string, mixed> */
    private function pricePackage(string $price = '3990.00'): array
    {
        return ['title' => 'Ціна ML-14', 'operations' => [
            ['op' => 'set_variant_price', 'variant' => 'ML-14', 'price' => $price, 'old_price' => null, 'expect' => ['price' => '4262.00', 'old_price' => null]],
        ]];
    }

    public function testTransportAndFirewall(): void
    {
        $init = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'claude-code', 'version' => '2']]];

        $json = $this->rpc(null, $init);
        self::assertResponseStatusCodeSame(401);
        self::assertStringStartsWith('Bearer realm="shop-admin-api"', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));
        self::assertSame('unauthorized', $json['error']);

        $this->rpc(null, $init, host: static::getContainer()->getParameter('site_host'));
        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 405], 'на хості сайту /mcp нема (405 — від catch-all сторінок сайту, лише GET)');
        self::assertNotSame('POST', $this->client->getResponse()->headers->get('Allow'));

        [$bearer] = $this->token();
        $this->rpc($bearer, null, method: 'GET');
        self::assertResponseStatusCodeSame(405);
        self::assertSame('POST', $this->client->getResponse()->headers->get('Allow'));

        $json = $this->rpc($bearer, $init, ['HTTP_ORIGIN' => 'https://evil.example']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('forbidden_origin', $json['error']);

        $json = $this->rpc($bearer, $init, ['HTTP_ORIGIN' => 'https://'.$this->adminHost]);
        self::assertResponseIsSuccessful();
        self::assertSame('2025-03-26', $json['result']['protocolVersion'], 'версію клієнта повторюємо');
        self::assertSame(McpController::SERVER_NAME, $json['result']['serverInfo']['name']);
        self::assertSame(['tools' => ['listChanged' => false]], $json['result']['capabilities']);
        self::assertStringContainsString('propose_changes', $json['result']['instructions']);
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'), 'stateless');

        $json = $this->rpc($bearer, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize']);
        self::assertSame(McpController::PROTOCOL, $json['result']['protocolVersion'], 'без версії — наша');

        $this->rpc($bearer, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        self::assertResponseStatusCodeSame(202);
        self::assertSame('', $this->client->getResponse()->getContent());

        $json = $this->rpc($bearer, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping']);
        self::assertSame([], $json['result']);

        $json = $this->rpc($bearer, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'resources/list']);
        self::assertResponseIsSuccessful();
        self::assertSame(-32601, $json['error']['code']);

        $json = $this->rpc($bearer, 'не json');
        self::assertResponseStatusCodeSame(400);
        self::assertSame(-32600, $json['error']['code']);
        self::assertNull($json['id']);

        $json = $this->rpc($bearer, [['jsonrpc' => '2.0', 'id' => 5, 'method' => 'ping']]);
        self::assertResponseStatusCodeSame(400, 'batch у 2025-06-18 прибрано');
    }

    public function testToolsListMatchesRegistryAndMe(): void
    {
        [$bearer, $user] = $this->token([ApiScope::CatalogRead, ApiScope::ContentWrite]);
        $json = $this->rpc($bearer, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        self::assertResponseIsSuccessful();
        $tools = $json['result']['tools'];
        $names = array_column($tools, 'name');
        self::assertSame([...McpTools::NO_SCOPE, ...array_keys(McpTools::SCOPES)], $names);
        self::assertNotContains('apply_changeset', $names, 'застосування — лише людина в адмінці (рішення власника 11.10 п. 1)');
        self::assertNotContains('set_stock', $names);
        foreach ($tools as $tool) {
            self::assertSame('object', $tool['inputSchema']['type'], $tool['name']);
            self::assertArrayHasKey('readOnlyHint', $tool['annotations'], $tool['name']);
            // пишуть: propose_changes (пакет) і theme_put/delete/revert (живий дизайн, 20.10.2026)
            self::assertSame(!\in_array($tool['name'], ['propose_changes', 'theme_put', 'theme_delete', 'theme_revert'], true), $tool['annotations']['readOnlyHint'], $tool['name']);
        }

        $me = $this->tool($bearer, 'me');
        self::assertArrayNotHasKey('isError', $me);
        self::assertSame(['id' => $user->getId(), 'name' => 'Олена', 'role' => 'ROLE_MANAGER'], $me['structuredContent']['user']);
        self::assertSame(['catalog:read', 'content:write'], $me['structuredContent']['scopes']);
        self::assertSame('text', $me['content'][0]['type']);
        self::assertStringNotContainsString($bearer, $me['content'][0]['text']);

        $unknown = $this->tool($bearer, 'apply_changeset', ['id' => 1]);
        self::assertTrue($unknown['isError']);
        self::assertStringContainsString('Невідомий інструмент', $unknown['content'][0]['text']);
    }

    public function testCatalogReadWithUntrustedWrapper(): void
    {
        [$bearer] = $this->token();
        $found = $this->tool($bearer, 'search_products', ['q' => 'ML-1']);
        self::assertSame(1, $found['structuredContent']['total']);
        self::assertSame('ML-14', $found['structuredContent']['items'][0]['sku']);

        $card = $this->tool($bearer, 'get_product', ['ref' => 'ML-14']);
        $uk = $card['structuredContent']['translations']['uk'];
        self::assertSame($this->ids['productId'], $card['structuredContent']['id']);
        self::assertSame('Двері ML 14', $uk['name'], 'назва — як є');
        self::assertSame('<untrusted_content source="product:'.$this->ids['productId'].':uk:description">Опис жирний</untrusted_content>', $uk['description'], 'HTML → текст у маркері');
        self::assertSame('<untrusted_content source="product:'.$this->ids['productId'].':uk:short_description">Сталеві двері</untrusted_content>', $uk['short_description']);
        self::assertArrayNotHasKey('stock', $card['structuredContent']['variant_list'][0]);
        self::assertStringContainsString('<untrusted_content', $card['content'][0]['text']);

        $raw = $this->tool($bearer, 'get_product', ['ref' => (string) $this->ids['productId'], 'fields' => ['description']]);
        self::assertSame('<p>Опис <b>жирний</b></p>', $raw['structuredContent']['translations']['uk']['description'], 'з fields — як у БД, для expect');
        self::assertStringStartsWith('<untrusted_content', $raw['structuredContent']['translations']['uk']['short_description']);

        $bad = $this->tool($bearer, 'get_product', ['ref' => 'NOPE']);
        self::assertTrue($bad['isError']);
        self::assertSame('Товар не знайдено', $bad['content'][0]['text']);
        self::assertSame(404, $bad['structuredContent']['status']);

        $bad = $this->tool($bearer, 'get_product', ['ref' => 'ML-14', 'fields' => ['name']]);
        self::assertTrue($bad['isError']);

        $cats = $this->tool($bearer, 'list_categories');
        self::assertSame([$this->ids['doorsId'], $this->ids['handlesId']], array_column($cats['structuredContent']['items'], 'id'));
        $page = $this->tool($bearer, 'get_page', ['ref' => 'dostavka']);
        self::assertSame('<untrusted_content source="page:'.$this->ids['pageId'].':uk:body">Текст</untrusted_content>', $page['structuredContent']['translations']['uk']['body']);
        $post = $this->tool($bearer, 'get_post', ['ref' => (string) $this->ids['postId']]);
        self::assertStringContainsString('Текст новини', $post['structuredContent']['translations']['uk']['body']);
        self::assertCount(1, $this->tool($bearer, 'list_pages')['structuredContent']['items']);
        self::assertCount(1, $this->tool($bearer, 'list_posts', ['limit' => 5])['structuredContent']['items']);
    }

    public function testReadToolsNeedCatalogReadScope(): void
    {
        [$bearer] = $this->token([ApiScope::ContentWrite]);
        $denied = $this->tool($bearer, 'search_products', ['q' => 'ML']);
        self::assertTrue($denied['isError']);
        self::assertSame('insufficient_scope', $denied['structuredContent']['error']);
        self::assertSame('catalog:read', $denied['structuredContent']['scope']);
        self::assertStringContainsString('catalog:read', $denied['content'][0]['text']);

        self::assertArrayNotHasKey('isError', $this->tool($bearer, 'list_my_changesets'), 'читання пакетів — з будь-яким скоупом');
    }

    public function testProposeCreatesChangesetAndPointsToAdmin(): void
    {
        [$bearer, $user] = $this->token([ApiScope::ContentWrite]);
        $dry = $this->tool($bearer, 'dry_run_changes', $this->contentPackage());
        self::assertArrayNotHasKey('isError', $dry);
        self::assertTrue($dry['structuredContent']['dry_run']);
        self::assertSame([], $dry['structuredContent']['errors']);
        self::assertSame(['short_description'], array_column($dry['structuredContent']['diff'][0]['changes'], 'field'));
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM admin_changeset'), 'dry-run нічого не зберігає');

        $result = $this->tool($bearer, 'propose_changes', $this->contentPackage());
        self::assertArrayNotHasKey('isError', $result);
        $data = $result['structuredContent'];
        self::assertSame('proposed', $data['status']);
        self::assertSame('ai_api', $data['source']);
        self::assertSame('https://'.$this->adminHost.'/changeset/'.$data['id'], $data['url']);
        self::assertStringContainsString($data['url'], $data['next_step']);
        self::assertStringContainsString($data['url'], $result['content'][0]['text']);
        $changeset = $this->em->find(Changeset::class, $data['id']);
        self::assertNotNull($changeset);
        self::assertSame($user->getId(), $changeset->getUser()?->getId());
        self::assertNotNull($changeset->getTokenId());
        self::assertSame('Сталеві двері', $this->em->getConnection()->fetchOne('SELECT short_description FROM catalog_product_translation WHERE product_id = ?', [$this->ids['productId']]), 'нічого не застосовано');

        $list = $this->tool($bearer, 'list_my_changesets', ['status' => 'proposed']);
        self::assertSame(1, $list['structuredContent']['total']);
        self::assertSame($data['id'], $list['structuredContent']['items'][0]['id']);
        $one = $this->tool($bearer, 'get_changeset', ['id' => $data['id']]);
        self::assertEquals($data['diff'], $one['structuredContent']['diff']);

        // чужий пакет не існує; адміністратор бачить
        [$other] = $this->token([ApiScope::CatalogRead], 'ihor@example.test');
        $foreign = $this->tool($other, 'get_changeset', ['id' => $data['id']]);
        self::assertTrue($foreign['isError']);
        self::assertSame('Пакет не знайдено', $foreign['content'][0]['text']);
        self::assertSame(0, $this->tool($other, 'list_my_changesets', ['all' => true])['structuredContent']['total'], 'all — лише адміністратору');
        [$admin] = $this->token([ApiScope::CatalogRead], 'admin@example.test', UserRole::Admin);
        self::assertSame(1, $this->tool($admin, 'list_my_changesets', ['all' => true])['structuredContent']['total']);
        self::assertArrayNotHasKey('isError', $this->tool($admin, 'get_changeset', ['id' => $data['id']]));
    }

    public function testProposeErrorsAreToolErrorsWithDetails(): void
    {
        [$bearer] = $this->token([ApiScope::ContentWrite]);
        $package = $this->contentPackage();
        $package['operations'][0]['expect'] = ['short_description' => 'щось інше'];
        $stale = $this->tool($bearer, 'propose_changes', $package);
        self::assertTrue($stale['isError']);
        self::assertSame('validation_failed', $stale['structuredContent']['error']);
        self::assertSame(422, $stale['structuredContent']['status']);
        self::assertSame('conflict', $stale['structuredContent']['errors'][0]['code']);
        self::assertStringContainsString('"conflict"', $stale['content'][0]['text'], 'подробиці — у тексті, їх читає модель');
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM admin_changeset'));

        $noOps = $this->tool($bearer, 'propose_changes', ['title' => 'x']);
        self::assertTrue($noOps['isError']);
        self::assertSame(400, $noOps['structuredContent']['status']);
    }

    /** Рев'ю C #81 M1: поточні значення з БД у diff.before і details.current — у маркері; after/expected (значення моделі) — як є. */
    public function testPlanWrapsCurrentDbValuesAsUntrusted(): void
    {
        [$bearer] = $this->token([ApiScope::ContentWrite]);
        $id = $this->ids['productId'];
        $package = ['title' => 'Опис ML-14', 'operations' => [
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $id, 'locale' => 'uk', 'fields' => ['description' => '<p>Новий опис</p>'], 'expect' => ['description' => '<p>Опис <b>жирний</b></p>']],
        ]];
        $expectedBefore = '<untrusted_content source="product:'.$id.':uk:description">Опис жирний</untrusted_content>';

        $dry = $this->tool($bearer, 'dry_run_changes', $package);
        self::assertArrayNotHasKey('isError', $dry);
        $change = $dry['structuredContent']['diff'][0]['changes'][0];
        self::assertSame('description', $change['field']);
        self::assertSame($expectedBefore, $change['before'], 'before — з БД, plain і в маркері');
        self::assertSame('<p>Новий опис</p>', $change['after'], 'after — значення моделі, як є');
        self::assertStringContainsString('"before": "<untrusted_content source=\\"product:'.$id.':uk:description\\"', $dry['content'][0]['text'], 'маркер і в тексті, який читає модель (operations з expect — її власний ввід)');

        $stale = $package;
        $stale['operations'][0]['expect'] = ['description' => 'щось інше'];
        $conflict = $this->tool($bearer, 'propose_changes', $stale);
        self::assertTrue($conflict['isError']);
        $details = $conflict['structuredContent']['errors'][0]['details'];
        self::assertSame('conflict', $conflict['structuredContent']['errors'][0]['code']);
        self::assertSame('щось інше', $details['expected'], 'expected — значення моделі');
        self::assertSame('<untrusted_content source="operation:0:description:current">Опис жирний</untrusted_content>', $details['current']);
        self::assertStringNotContainsString('<b>жирний</b>', $conflict['content'][0]['text'], 'і в тексті помилки теж');
        self::assertStringContainsString('"current": "<untrusted_content source=\\"operation:0:description:current\\">', $conflict['content'][0]['text']);

        $created = $this->tool($bearer, 'propose_changes', $package);
        self::assertArrayNotHasKey('isError', $created);
        self::assertSame($expectedBefore, $created['structuredContent']['diff'][0]['changes'][0]['before']);
        $stored = $this->tool($bearer, 'get_changeset', ['id' => $created['structuredContent']['id']]);
        self::assertSame($expectedBefore, $stored['structuredContent']['diff'][0]['changes'][0]['before'], 'get_changeset читає diff з БД — теж у маркері');
        self::assertSame('<p>Опис <b>жирний</b></p>', $this->em->getConnection()->fetchOne('SELECT description FROM catalog_product_translation WHERE product_id = ? AND locale = ?', [$id, 'uk']), 'у БД — сирий HTML, нічого не застосовано');
        self::assertSame('<p>Опис <b>жирний</b></p>', $this->em->find(Changeset::class, $created['structuredContent']['id'])?->getDiff()[0]['changes'][0]['before'] ?? null, 'у пакеті diff зберігається сирим — маркер лише на виході MCP');
    }

    public function testWriteScopeFollowsOperations(): void
    {
        [$content] = $this->token([ApiScope::ContentWrite], 'c@example.test');
        $denied = $this->tool($content, 'propose_changes', $this->pricePackage());
        self::assertTrue($denied['isError']);
        self::assertSame('prices:write', $denied['structuredContent']['scope']);

        [$prices] = $this->token([ApiScope::PricesWrite], 'p@example.test');
        $denied = $this->tool($prices, 'dry_run_changes', $this->contentPackage());
        self::assertTrue($denied['isError']);
        self::assertSame('content:write', $denied['structuredContent']['scope']);
        self::assertArrayNotHasKey('isError', $this->tool($prices, 'dry_run_changes', $this->pricePackage()));

        [$read] = $this->token([ApiScope::CatalogRead], 'r@example.test');
        $denied = $this->tool($read, 'propose_changes', ['title' => 'x', 'operations' => []]);
        self::assertTrue($denied['isError']);
        self::assertSame('insufficient_scope', $denied['structuredContent']['error'], 'без write-скоупу — відмова ще до парсера');
    }

    /** Та сама квота, що в REST (рев'ю C #77 M1): застосовані за добу + у чинних proposed пакетах токена; помилка — isError 429. */
    public function testPriceQuotaCountsAppliedAndPendingLikeRest(): void
    {
        [$bearer] = $this->token([ApiScope::PricesWrite], 'p@example.test');
        $tokenId = (int) $this->tool($bearer, 'me')['structuredContent']['token']['id'];
        /** @var int $limit */
        $limit = static::getContainer()->getParameter('app.ai_price_ops_per_day');
        $db = $this->em->getConnection();
        for ($i = 0; $i < $limit - 1; ++$i) {
            $db->insert('admin_audit_entry', ['occurred_at' => date('Y-m-d H:i:s'), 'source' => 'ai_api', 'action' => 'update', 'entity_type' => 'product_variant', 'entity_id' => $this->ids['variantId'], 'field' => 'priceMinor', 'token_id' => $tokenId, 'changeset_id' => 1]);
        }
        self::assertArrayNotHasKey('isError', $this->tool($bearer, 'propose_changes', $this->pricePackage()), 'рівно до межі — можна');

        $over = $this->tool($bearer, 'propose_changes', $this->pricePackage('3980.00'));
        self::assertTrue($over['isError'], 'запропоноване вже в черзі — наступний пакет понад межу');
        $data = $over['structuredContent'];
        self::assertSame(['error' => 'price_ops_limit', 'status' => 429, 'limit' => $limit, 'used' => $limit, 'applied' => $limit - 1, 'pending' => 1, 'requested' => 1], array_intersect_key($data, array_flip(['error', 'status', 'limit', 'used', 'applied', 'pending', 'requested'])));
        self::assertStringContainsString('price_ops_limit', $over['content'][0]['text']);
        self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM admin_changeset'), 'другий пакет не збережено');
    }
}
