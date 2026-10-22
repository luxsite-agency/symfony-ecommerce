<?php

declare(strict_types=1);

namespace App\Tests\Functional\AdminApi;

use App\Core\AdminApi\AdminApiTokens;
use App\Core\AdminApi\ApiScope;
use App\Core\Changeset\ChangesetApplier;
use App\Core\Changeset\Entity\Changeset;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * REST пакетів змін (E15 PR-4): POST створює proposed з url сторінки, скоуп за складом пакета (ціни — prices:write),
 * dry-run без збереження, 422/413 від ядра, список своїх / усіх для адміна, чужий пакет — 404, добовий ліміт цінових — 429.
 */
final class ChangesetApiTest extends WebTestCase
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
     * @return array{string, User, int}
     */
    private function token(array $scopes, string $email = 'olena@example.test', UserRole $role = UserRole::Manager): array
    {
        $user = new User($email, 'Олена', $role);
        $this->em->persist($user);
        $this->em->flush();
        $issued = static::getContainer()->get(AdminApiTokens::class)->issue($user, 'cc', $scopes, 30, $user);

        return [$issued->plain, $user, (int) $issued->token->getId()];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, string $bearer, ?array $body = null): array
    {
        $this->client->request($method, 'https://'.$this->adminHost.$path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer, 'CONTENT_TYPE' => 'application/json'], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    private function contentPackage(): array
    {
        return ['title' => 'Опис ML-14', 'operations' => [
            ['op' => 'set_translation', 'entity' => 'product', 'id' => $this->ids['productId'], 'locale' => 'uk', 'fields' => ['short_description' => 'Вхідні сталеві двері', 'slug' => 'dveri-ml-14-vhidni'], 'expect' => ['short_description' => 'Сталеві двері', 'slug' => 'dveri-ml-14']],
        ]];
    }

    /** @return array<string, mixed> */
    private function pricePackage(string $price = '3990.00'): array
    {
        return ['title' => 'Ціна ML-14', 'operations' => [
            ['op' => 'set_variant_price', 'variant' => 'ML-14', 'price' => $price, 'old_price' => null, 'expect' => ['price' => '4262.00', 'old_price' => null]],
        ]];
    }

    public function testCreateReturnsProposedChangesetWithAdminUrl(): void
    {
        [$bearer, $user, $tokenId] = $this->token([ApiScope::ContentWrite]);
        $json = $this->call('POST', '/api/admin/v1/changesets', $bearer, $this->contentPackage());
        self::assertResponseStatusCodeSame(201);
        self::assertSame('proposed', $json['status']);
        self::assertSame('Опис ML-14', $json['title']);
        self::assertSame('https://'.$this->adminHost.'/changeset/'.$json['id'], $json['url']);
        self::assertSame($json['url'], $this->client->getResponse()->headers->get('Location'));
        self::assertSame('ai_api', $json['source']);
        self::assertSame(['short_description', 'slug'], array_column($json['diff'][0]['changes'], 'field'));
        self::assertSame([], $json['risk_flags']);
        self::assertFalse($json['has_price_operations']);

        $changeset = $this->em->find(Changeset::class, $json['id']);
        self::assertNotNull($changeset);
        self::assertSame($tokenId, $changeset->getTokenId());
        self::assertSame($user->getId(), $changeset->getUser()?->getId());
        self::assertSame('Сталеві двері', $this->em->getConnection()->fetchOne('SELECT short_description FROM catalog_product_translation WHERE product_id = ?', [$this->ids['productId']]), 'dry-run нічого не змінив');
    }

    public function testScopeFollowsOperations(): void
    {
        [$content] = $this->token([ApiScope::ContentWrite], 'c@example.test');
        $this->call('POST', '/api/admin/v1/changesets', $content, $this->pricePackage());
        self::assertResponseStatusCodeSame(403);
        self::assertSame('insufficient_scope', json_decode((string) $this->client->getResponse()->getContent(), true)['error']);
        self::assertStringContainsString('scope="prices:write"', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));

        [$prices] = $this->token([ApiScope::PricesWrite], 'p@example.test');
        $this->call('POST', '/api/admin/v1/changesets', $prices, $this->contentPackage());
        self::assertResponseStatusCodeSame(403);
        $mixed = ['title' => 'Обидва', 'operations' => [...$this->pricePackage()['operations'], ...$this->contentPackage()['operations']]];
        $this->call('POST', '/api/admin/v1/changesets', $prices, $mixed);
        self::assertResponseStatusCodeSame(403, 'змішаний пакет потребує обох скоупів');

        [$read] = $this->token([ApiScope::CatalogRead], 'r@example.test');
        $this->call('POST', '/api/admin/v1/changesets', $read, $this->contentPackage());
        self::assertResponseStatusCodeSame(403);
        $json = $this->call('POST', '/api/admin/v1/changesets', $prices, $this->pricePackage());
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($json['has_price_operations']);
    }

    public function testDryRunAndValidationErrors(): void
    {
        [$bearer] = $this->token([ApiScope::ContentWrite, ApiScope::PricesWrite]);
        $json = $this->call('POST', '/api/admin/v1/changesets/dry-run', $bearer, $this->pricePackage('2000.00'));
        self::assertResponseIsSuccessful();
        self::assertTrue($json['dry_run']);
        self::assertSame(['price_jump'], $json['risk_flags']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM admin_changeset'), 'dry-run не зберігає');

        $bad = $this->pricePackage();
        $bad['operations'][0]['expect']['price'] = '1.00';
        $json = $this->call('POST', '/api/admin/v1/changesets', $bearer, $bad);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $json['error']);
        self::assertSame('conflict', $json['errors'][0]['code']);

        $many = ['title' => 'Багато', 'operations' => array_fill(0, 201, $this->pricePackage()['operations'][0])];
        $this->call('POST', '/api/admin/v1/changesets', $bearer, $many);
        self::assertResponseStatusCodeSame(413);

        $this->client->request('POST', 'https://'.$this->adminHost.'/api/admin/v1/changesets', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer, 'CONTENT_TYPE' => 'application/json'], content: '{not json');
        self::assertResponseStatusCodeSame(400);
    }

    public function testListAndShowAreScopedToOwner(): void
    {
        [$olena] = $this->token([ApiScope::ContentWrite, ApiScope::CatalogRead]);
        [$ihor] = $this->token([ApiScope::CatalogRead], 'ihor@example.test');
        [$admin] = $this->token([ApiScope::CatalogRead], 'admin@example.test', UserRole::Admin);
        $created = $this->call('POST', '/api/admin/v1/changesets', $olena, $this->contentPackage());
        $id = (int) $created['id'];

        self::assertSame([$id], array_column($this->call('GET', '/api/admin/v1/changesets', $olena)['items'], 'id'));
        self::assertSame('proposed', $this->call('GET', '/api/admin/v1/changesets?status=proposed', $olena)['items'][0]['status']);
        self::assertSame([], $this->call('GET', '/api/admin/v1/changesets?status=applied', $olena)['items']);
        self::assertSame([], $this->call('GET', '/api/admin/v1/changesets', $ihor)['items'], 'чужих не видно');
        self::assertSame([], $this->call('GET', '/api/admin/v1/changesets', $admin)['items'], 'адмін типово теж бачить свої');
        self::assertSame([$id], array_column($this->call('GET', '/api/admin/v1/changesets?all=1', $admin)['items'], 'id'));
        self::assertSame([], $this->call('GET', '/api/admin/v1/changesets?all=1', $ihor)['items'], 'all=1 для менеджера нічого не додає');

        self::assertSame($id, $this->call('GET', '/api/admin/v1/changesets/'.$id, $olena)['id']);
        self::assertArrayHasKey('diff', $this->call('GET', '/api/admin/v1/changesets/'.$id, $admin));
        $this->call('GET', '/api/admin/v1/changesets/'.$id, $ihor);
        self::assertResponseStatusCodeSame(404, 'чужий — 404, не 403');
    }

    public function testDailyPriceOperationsLimitIs429(): void
    {
        [$bearer, , $tokenId] = $this->token([ApiScope::PricesWrite]);
        /** @var int $limit */
        $limit = static::getContainer()->getParameter('app.ai_price_ops_per_day');
        $db = $this->em->getConnection();
        for ($i = 0; $i < $limit; ++$i) {
            $db->insert('admin_audit_entry', ['occurred_at' => date('Y-m-d H:i:s'), 'source' => 'ai_api', 'action' => 'update', 'entity_type' => 'product_variant', 'entity_id' => $this->ids['variantId'], 'field' => 'priceMinor', 'token_id' => $tokenId, 'changeset_id' => 1]);
        }
        $json = $this->call('POST', '/api/admin/v1/changesets', $bearer, $this->pricePackage());
        self::assertResponseStatusCodeSame(429);
        self::assertSame('price_ops_limit', $json['error']);
        self::assertSame($limit, $json['used']);
        self::assertSame('3600', $this->client->getResponse()->headers->get('Retry-After'));
        // контентний пакет ліміт не чіпає
        [$content] = $this->token([ApiScope::ContentWrite], 'c@example.test');
        $this->call('POST', '/api/admin/v1/changesets', $content, $this->contentPackage());
        self::assertResponseStatusCodeSame(201);
    }

    /** Рев'ю C #77 M1: ліміт рахує й цінові операції у чинних proposed пакетах, відхилення вивільняє квоту. */
    public function testPriceLimitCountsPendingProposals(): void
    {
        [$bearer, $user, $tokenId] = $this->token([ApiScope::PricesWrite]);
        /** @var int $limit */
        $limit = static::getContainer()->getParameter('app.ai_price_ops_per_day');
        $db = $this->em->getConnection();
        for ($i = 0; $i < $limit - 1; ++$i) {
            $db->insert('admin_audit_entry', ['occurred_at' => date('Y-m-d H:i:s'), 'source' => 'ai_api', 'action' => 'update', 'entity_type' => 'product_variant', 'entity_id' => $this->ids['variantId'], 'field' => 'priceMinor', 'token_id' => $tokenId, 'changeset_id' => 1]);
        }
        $first = $this->call('POST', '/api/admin/v1/changesets', $bearer, $this->pricePackage());
        self::assertResponseStatusCodeSame(201, 'рівно до межі — можна');

        $json = $this->call('POST', '/api/admin/v1/changesets', $bearer, $this->pricePackage('3980.00'));
        self::assertResponseStatusCodeSame(429, 'запропоноване вже в черзі — наступний пакет понад межу');
        self::assertSame('price_ops_limit', $json['error']);
        self::assertSame(['used' => $limit, 'applied' => $limit - 1, 'pending' => 1, 'requested' => 1], array_intersect_key($json, array_flip(['used', 'applied', 'pending', 'requested'])));

        // прострочений proposed квоту не тримає
        $db->executeStatement('UPDATE admin_changeset SET expires_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 60), $first['id']]);
        $this->call('POST', '/api/admin/v1/changesets', $bearer, $this->pricePackage('3980.00'));
        self::assertResponseStatusCodeSame(201);

        // відхилений — теж
        $second = $this->em->find(Changeset::class, $this->call('GET', '/api/admin/v1/changesets?status=proposed', $bearer)['items'][0]['id']);
        self::assertNotNull($second);
        $fresh = $this->em->find(User::class, $user->getId());
        self::assertNotNull($fresh);
        static::getContainer()->get(ChangesetApplier::class)->reject($second, $fresh);
        $this->call('POST', '/api/admin/v1/changesets', $bearer, $this->pricePackage('3970.00'));
        self::assertResponseStatusCodeSame(201);
    }
}
