<?php

declare(strict_types=1);

namespace App\Tests\Functional\AdminApi;

use App\Core\AdminApi\AdminApiTokens;
use App\Core\AdminApi\ApiScope;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** REST читання каталогу (E15 PR-4, catalog:read): пошук за SKU/назвою, картка за id і SKU, категорії деревом, сторінки й записи, 403 без скоупу. */
final class CatalogApiTest extends WebTestCase
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

    /** @param list<ApiScope> $scopes */
    private function token(array $scopes = [ApiScope::CatalogRead], string $email = 'olena@example.test'): string
    {
        $user = new User($email, 'Олена', UserRole::Manager);
        $this->em->persist($user);
        $this->em->flush();

        return static::getContainer()->get(AdminApiTokens::class)->issue($user, 'cc', $scopes, 30, $user)->plain;
    }

    /** @return array<string, mixed> */
    private function get(string $path, string $bearer): array
    {
        $this->client->request('GET', 'https://'.$this->adminHost.$path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]);
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($json) ? $json : [];
    }

    public function testProductsSearchAndCard(): void
    {
        $bearer = $this->token();
        $json = $this->get('/api/admin/v1/products?q=ML-1', $bearer);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $json['total']);
        self::assertSame('ML-14', $json['items'][0]['sku']);
        self::assertSame('Двері ML 14', $json['items'][0]['name']['uk']);
        self::assertSame('4262.00', $json['items'][0]['price']);
        self::assertSame([$this->ids['doorsId']], $json['items'][0]['categories']);
        self::assertStringContainsString('/dveri-ml-14', (string) $json['items'][0]['url']);

        self::assertSame(1, $this->get('/api/admin/v1/products?q=Двері', $bearer)['total'], 'за назвою');
        self::assertSame(0, $this->get('/api/admin/v1/products?q=Двері&category='.$this->ids['handlesId'], $bearer)['total'], 'фільтр за категорією');
        self::assertSame(0, $this->get('/api/admin/v1/products?q=%25', $bearer)['total'], '% екранується');

        $card = $this->get('/api/admin/v1/products/ML-14', $bearer);
        self::assertResponseIsSuccessful();
        self::assertSame($this->ids['productId'], $card['id']);
        self::assertSame('dveri-ml-14', $card['translations']['uk']['slug']);
        self::assertSame('<p>Опис <b>жирний</b></p>', $card['translations']['uk']['description']);
        self::assertSame('4262.00', $card['variant_list'][0]['price']);
        self::assertSame([], $card['variant_list'][0]['price_tiers']);
        self::assertArrayNotHasKey('stock', $card['variant_list'][0], 'залишків у API нема (ADR E15 §6)');
        self::assertSame($card, $this->get('/api/admin/v1/products/'.$this->ids['productId'], $bearer), 'за id — те саме');

        $this->get('/api/admin/v1/products/NOPE', $bearer);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCategoriesPagesPosts(): void
    {
        $bearer = $this->token();
        $tree = $this->get('/api/admin/v1/categories', $bearer)['items'];
        self::assertSame(['Двері', 'Ручки'], array_column($tree, 'name'));
        self::assertSame([0, 1], array_column($tree, 'depth'));
        self::assertSame($this->ids['doorsId'], $tree[1]['parent_id']);

        $page = $this->get('/api/admin/v1/pages/dostavka', $bearer);
        self::assertSame($this->ids['pageId'], $page['id']);
        self::assertSame('<p>Текст</p>', $page['translations']['uk']['body']);
        self::assertArrayNotHasKey('body', $this->get('/api/admin/v1/pages', $bearer)['items'][0]['translations']['uk'], 'у списку — без тексту');

        $post = $this->get('/api/admin/v1/posts/'.$this->ids['postId'], $bearer);
        self::assertSame('Новина', $post['translations']['uk']['title']);
        self::assertCount(1, $this->get('/api/admin/v1/posts', $bearer)['items']);
    }

    public function testCatalogRequiresScope(): void
    {
        $this->get('/api/admin/v1/products', $this->token([ApiScope::ContentWrite]));
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('scope="catalog:read"', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));
    }
}
