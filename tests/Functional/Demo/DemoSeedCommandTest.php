<?php

declare(strict_types=1);

namespace App\Tests\Functional\Demo;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\CategoryFilterAttribute;
use App\Core\Catalog\Entity\CategoryTranslation;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Content\Entity\Post;
use App\Core\Demo\DemoCatalog;
use App\Core\Demo\DemoTexts;
use App\Core\Order\Entity\Order;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Message\PushOrderToCrm;
use App\Core\Order\Message\SendCustomerOrderEmail;
use App\Core\Order\Message\SendShopOrderEmail;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * app:demo:seed на справжній БД: дані з'являються всіма мовами app.locales, повторний запуск не дублює, сторінки
 * каталогу й блогу працюють, --purge прибирає лише демо (не-демо товар, схоже замовлення покупця й категорія з
 * не-демо товаром лишаються). Перебудова індексу фільтрів — DDL (RENAME TABLE) з неявним COMMIT, тож без
 * DAMA-відкату: усе, що створено, прибирається в tearDown.
 */
#[SkipDatabaseRollback]
final class DemoSeedCommandTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;
    private ?string $notifyEmail;
    private ?int $realProductId = null;
    private ?int $realOrderId = null;
    private ?int $realPromotionId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // один контейнер (і EntityManager) на команду, HTTP-запити й перевірки
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = static::getContainer()->get(Connection::class);
        $this->notifyEmail = $this->db->fetchOne('SELECT value FROM setting WHERE code = ?', [SettingKeys::ORDERS_NOTIFY_EMAIL]) ?: null;
        self::assertSame(0, $this->demoProducts(), 'БД тестів без демо-даних з попередніх прогонів');
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->command()->execute(['--purge' => true]);
        if (null !== $this->realPromotionId) {
            $this->db->executeStatement('DELETE FROM pricing_promotion WHERE id = ?', [$this->realPromotionId]);
        }
        if (null !== $this->realOrderId) {
            $this->db->executeStatement('DELETE FROM sales_order WHERE id = ?', [$this->realOrderId]);
        }
        if (null !== $this->realProductId) {
            $this->db->executeStatement('DELETE FROM catalog_product WHERE id = ?', [$this->realProductId]);
            $this->command()->execute(['--purge' => true]); // категорії, що лишались через не-демо товар
        }
        $this->db->executeStatement('UPDATE setting SET value = ? WHERE code = ?', [$this->notifyEmail, SettingKeys::ORDERS_NOTIFY_EMAIL]);
        static::getContainer()->get(Settings::class)->invalidate();
        parent::tearDown();
    }

    public function testSeedIsIdempotentServesTheSiteAndPurgeRemovesOnlyDemoData(): void
    {
        $tester = $this->command();
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        self::assertSame(49, $this->demoProducts());
        self::assertGreaterThanOrEqual(75, (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_product_variant WHERE sku LIKE ?', ['DEMO-%']));
        self::assertSame(16, $this->demoCategories());
        self::assertSame(10, (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_page_translation WHERE locale = 'uk' AND slug LIKE 'demo-%'"));
        $this->assertDemoPagesNavigation();
        $this->assertDemoPromotions();
        self::assertSame(4, (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_post_translation WHERE locale = 'uk' AND slug LIKE 'demo-%'"));
        self::assertGreaterThanOrEqual(49, (int) $this->db->fetchOne("SELECT COUNT(*) FROM catalog_media WHERE path LIKE 'demo-%'"));
        self::assertGreaterThan(0, $this->em->getRepository(CategoryFilterAttribute::class)->count([]));
        $states = $this->demoOrderStates();
        self::assertCount(14, $states, 'гостьові замовлення по ~180 днях');
        self::assertSame(['cancelled', 'completed', 'confirmed', 'new', 'shipped'], array_values(array_unique($states)));
        static::getContainer()->get(Settings::class)->invalidate();
        self::assertSame($this->notifyEmail ?? 'demo-orders@example.com', static::getContainer()->get(Settings::class)->get(SettingKeys::ORDERS_NOTIFY_EMAIL), 'порожнє поле заповнено, задане не перезаписано');

        // усі мови app.locales, кожна своїм текстом з DemoTexts, без позначок « [xx]»
        $locales = $this->locales();
        $door = $this->demoProduct('DEMO-DV-P01');
        $sorted = $locales;
        sort($sorted);
        self::assertSame($sorted, $door->getTranslatedLocales());
        foreach ($locales as $locale) {
            $name = (string) $door->getTranslation($locale)?->getName();
            self::assertNotSame('', $name, $locale);
            self::assertSame(DemoTexts::products()['DV-P01']['name'][$locale] ?? DemoTexts::products()['DV-P01']['name']['uk'], $name, $locale);
        }
        $this->assertNoPlaceholders();
        self::assertCount(4, $door->getVariants());
        self::assertGreaterThan(0, $door->getOptionGroups()->count(), 'фурнітура опціями-компонентами');
        $media = $door->getMedia()->first();
        self::assertNotFalse($media);
        self::assertFileExists(static::getContainer()->getParameter('kernel.project_dir').'/public/media/'.$media->getMedia()->getPath());

        // демо-замовлення — без CRM і листів
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        foreach ($transport->getSent() as $envelope) {
            self::assertNotContains($envelope->getMessage()::class, [PushOrderToCrm::class, SendCustomerOrderEmail::class, SendShopOrderEmail::class]);
        }

        // повторний запуск нічого не дублює
        $again = $this->command();
        self::assertSame(0, $again->execute([]));
        self::assertStringContainsString('нічого не додано', $again->getDisplay());
        self::assertSame(49, $this->demoProducts());
        self::assertSame(16, $this->demoCategories());
        $states = $this->demoOrderStates();
        self::assertCount(14, $states, 'гостьові замовлення по ~180 днях');
        self::assertSame(['cancelled', 'completed', 'confirmed', 'new', 'shipped'], array_values(array_unique($states)));
        self::assertSame(10, (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_page_translation WHERE locale = 'uk' AND slug LIKE 'demo-%'"));
        $this->assertDemoPagesNavigation();
        $this->assertDemoPromotions();

        $this->assertSitePages();
        $this->assertEnglishStorefront();
        $this->assertStaleTranslationsRefreshed();

        // не-демо дані поруч: товар у демо-категорії і замовлення з коментарем «DEMO #…», але телефоном покупця
        $interior = $this->demoCategory('uk', 'demo-mizhkimnatni-dveri');
        $real = new Product('REAL-'.bin2hex(random_bytes(3)), 'UAH');
        $real->getDefaultVariant()->setPrice(Money::of(10_000, 'UAH'));
        $real->getDefaultVariant()->setStock('5');
        $real->addCategory($interior);
        $real->translate('uk')->setName('Справжній товар')->setSlug('real-'.bin2hex(random_bytes(3)));
        $this->em->persist($real);
        $this->em->flush();
        $this->realProductId = (int) $real->getId();
        $order = static::getContainer()->get(PlaceOrder::class)->place(new PlaceOrderRequest(
            OrderSource::Quick, 'uk', 'Покупець', '+380671112233', [['variant' => $real->getDefaultVariant(), 'quantity' => '1']], comment: 'DEMO #1: схоже на демо, але це покупець',
        ));
        $this->realOrderId = (int) $order->getId();
        $realPromotion = new Promotion('Знижка DEMO: справжня акція', 'UAH');
        $realPromotion->setPercentDiscount('1');
        $realPromotion->setWholeCatalog(true);
        $realPromotion->setActive(false);
        $this->em->persist($realPromotion);
        $this->em->flush();
        $this->realPromotionId = (int) $realPromotion->getId();
        $mediaPath = $media->getMedia()->getPath();

        $purge = $this->command();
        self::assertSame(0, $purge->execute(['--purge' => true]), $purge->getDisplay());
        $this->em->clear();
        self::assertSame(0, $this->demoProducts());
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM sales_order WHERE comment LIKE 'DEMO #%' AND phone LIKE '+3800000000__'"));
        self::assertNotNull($this->em->find(Product::class, $this->realProductId), 'не-демо товар лишився');
        self::assertNotNull($this->em->find(Order::class, $this->realOrderId), 'замовлення покупця лишилось');
        self::assertSame(0, $this->demoPromotions());
        self::assertNotNull($this->em->find(Promotion::class, $this->realPromotionId), 'акція, де «DEMO:» не на початку назви, лишилась');
        self::assertSame(2, $this->demoCategories(), 'лишились лише категорія з не-демо товаром і її предок');
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM catalog_media WHERE path LIKE 'demo-%'"));
        self::assertFileDoesNotExist(static::getContainer()->getParameter('kernel.project_dir').'/public/media/'.$mediaPath);
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_page_translation WHERE slug LIKE 'demo-%'"));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_post_translation WHERE slug LIKE 'demo-%'"));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM catalog_attribute WHERE code LIKE 'demo\\_%'"));
    }

    private function assertSitePages(): void
    {
        $default = (string) static::getContainer()->getParameter('app.default_locale');
        $router = static::getContainer()->get(UrlGeneratorInterface::class);
        $interior = $this->demoCategory($default, 'demo-mizhkimnatni-dveri');

        foreach ($this->locales() as $locale) {
            $path = (string) $interior->getTranslation($locale)?->getPath();
            $crawler = $this->client->request('GET', $router->generate('site_category', ['path' => $path, '_locale' => $locale]));
            self::assertResponseIsSuccessful($locale);
            self::assertCount(1, $crawler->filter('fieldset.facet[data-facet="demo_color"]'), $locale.': фасет осі варіантів');
            self::assertCount(1, $crawler->filter('fieldset.facet[data-facet="demo_glass"]'), $locale.': булевий фасет');
            self::assertGreaterThanOrEqual(10, (int) $crawler->filter('.found')->attr('data-total'));
        }

        $grinders = $this->client->request('GET', $router->generate('site_category', ['path' => (string) $this->demoCategory($default, 'demo-bolharky')->getTranslation($default)?->getPath(), '_locale' => $default]));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $grinders->filter('fieldset.facet[data-facet="demo_power"]'), 'числовий фасет-діапазон');

        // акція «інструмент −10 %» діє на товар підкатегорії (дриль 5200 → 4680)
        $currency = (string) static::getContainer()->getParameter('app.default_currency');
        $drill = $this->demoProduct('DEMO-TL-D04');
        $drillPage = $this->client->request('GET', $router->generate('site_product', ['slug' => (string) $drill->getTranslation($default)?->getSlug(), '_locale' => $default]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(Money::of(468_000, $currency)->format($default), $drillPage->filter('.price')->text());
        self::assertStringContainsString(Money::of(520_000, $currency)->format($default), $drillPage->filter('.price .old-price')->text());

        $door = $this->demoProduct('DEMO-DV-P01');
        $page = $this->client->request('GET', $router->generate('site_product', ['slug' => (string) $door->getTranslation($default)?->getSlug(), '_locale' => $default]));
        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(1, $page->filter('img')->count());

        $blog = $this->client->request('GET', $router->generate('site_blog', ['_locale' => $default]));
        self::assertResponseIsSuccessful();
        /** @var list<Post> $posts */
        $posts = $this->em->createQueryBuilder()->select('p')->from(Post::class, 'p')->join('p.translations', 't')
            ->where('t.slug LIKE :demo')->setParameter('demo', 'demo-%')->getQuery()->getResult();
        $published = $hidden = 0;
        foreach ($posts as $post) {
            $title = (string) $post->getTranslation($default)?->getTitle();
            if ($post->isPublished()) {
                self::assertStringContainsString($title, $blog->text());
                ++$published;
            } else {
                self::assertStringNotContainsString($title, $blog->text(), 'запис з майбутньою датою не видно');
                ++$hidden;
            }
        }
        self::assertSame([3, 1], [$published, $hidden]);
    }

    /** Футер: усі демо-сторінки в порядку DemoTexts::pages(); шапка: лише about, delivery, contacts; нові сторінки мають en. */
    private function assertDemoPagesNavigation(): void
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT t.title, p.show_in_header, p.show_in_footer FROM content_page p JOIN content_page_translation t ON t.page_id = p.id AND t.locale = 'uk' WHERE t.slug LIKE 'demo-%' ORDER BY p.position, p.id",
        );
        $pages = DemoTexts::pages();
        self::assertSame(array_values(array_map(static fn (array $page): string => $page['title']['uk'], $pages)), array_column($rows, 'title'));
        self::assertSame(
            [$pages['about']['title']['uk'], $pages['delivery']['title']['uk'], $pages['contacts']['title']['uk']],
            array_column(array_values(array_filter($rows, static fn (array $row): bool => (bool) $row['show_in_header'])), 'title'),
        );
        self::assertCount(\count($pages), array_filter($rows, static fn (array $row): bool => (bool) $row['show_in_footer']));
        if (\in_array('en', $this->locales(), true)) {
            self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_page_translation WHERE locale = 'en' AND slug LIKE 'demo-%' AND title = ?", [$pages['privacy']['title']['en']]));
        }
    }

    /** У жодному перекладі демо-даних нема позначки « [xx]»; англійські назви — без кирилиці (DemoTexts і DemoCatalog мають en для всього). */
    private function assertNoPlaceholders(): void
    {
        $columns = [
            'catalog_product_translation' => 'name', 'catalog_category_translation' => 'name', 'content_page_translation' => 'title',
            'content_post_translation' => 'title', 'content_post_category_translation' => 'name', 'catalog_attribute_translation' => 'name',
            'catalog_attribute_value_translation' => 'value', 'catalog_option_group_translation' => 'name', 'catalog_option_value_translation' => 'label',
        ];
        foreach ($columns as $table => $column) {
            foreach ($this->locales() as $locale) {
                self::assertSame(0, (int) $this->db->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE locale = ? AND %s LIKE ?', $table, $column), [$locale, '%['.$locale.']%']), $table.' '.$locale);
            }
        }
        if (!\in_array('en', $this->locales(), true)) {
            return;
        }
        foreach (['catalog_product_translation' => 'name', 'catalog_category_translation' => 'name', 'content_page_translation' => 'title', 'content_post_translation' => 'title', 'content_post_category_translation' => 'name'] as $table => $column) {
            $cyrillic = $this->db->fetchFirstColumn(\sprintf("SELECT %s FROM %s WHERE locale = 'en' AND slug LIKE 'demo-%%' AND %s REGEXP '[а-яА-ЯіІїЇєЄґҐ]'", $column, $table, $column));
            self::assertSame([], $cyrillic, $table);
            self::assertSame(0, (int) $this->db->fetchOne(\sprintf("SELECT COUNT(*) FROM %s WHERE locale = 'en' AND slug LIKE 'demo-%%' AND slug REGEXP '[^a-z0-9-]'", $table)), $table.': англійські слаги');
        }
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM catalog_product_translation t JOIN catalog_product_variant v ON v.product_id = t.product_id AND v.sku LIKE 'DEMO-%' WHERE t.locale = 'en' AND t.name REGEXP '[а-яА-ЯіІїЇєЄґҐ]'"));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM catalog_product_translation t JOIN catalog_product_variant v ON v.product_id = t.product_id AND v.sku LIKE 'DEMO-%' WHERE t.locale = 'en' AND t.slug REGEXP '[^a-z0-9-]'"), 'англійські слаги товарів');
    }

    /** /en/: меню, категорія й картка товару англійською — жодної позначки «[en]» і кирилиці в назвах. */
    private function assertEnglishStorefront(): void
    {
        if (!\in_array('en', $this->locales(), true)) {
            return;
        }
        $router = static::getContainer()->get(UrlGeneratorInterface::class);
        $home = $this->client->request('GET', $router->generate('site_home', ['_locale' => 'en']));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('[en]', (string) $this->client->getResponse()->getContent());
        $menu = $home->filter('.nav-bar a, #site-nav a')->each(static fn ($a): string => trim($a->text()));
        self::assertContains('About us', $menu);
        self::assertContains('Doors', $menu);
        self::assertDoesNotMatchRegularExpression('/[а-яА-ЯіІїЇєЄґҐ]/u', implode(' | ', $menu));

        $interior = $this->demoCategory('uk', 'demo-mizhkimnatni-dveri');
        $category = $this->client->request('GET', $router->generate('site_category', ['path' => (string) $interior->getTranslation('en')?->getPath(), '_locale' => 'en']));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('[en]', (string) $this->client->getResponse()->getContent());
        self::assertSame('Interior doors', trim($category->filter('h1')->text()));
        self::assertDoesNotMatchRegularExpression('/[а-яА-ЯіІїЇєЄґҐ]/u', $category->filter('main')->text(), 'категорія /en/: товари, фасети, одиниці');

        $door = $this->demoProduct('DEMO-DV-P01');
        $product = $this->client->request('GET', $router->generate('site_product', ['slug' => (string) $door->getTranslation('en')?->getSlug(), '_locale' => 'en']));
        self::assertResponseIsSuccessful();
        self::assertSame('linea-1-door', $door->getTranslation('en')?->getSlug());
        self::assertDoesNotMatchRegularExpression('/[а-яА-ЯіІїЇєЄґҐ]/u', $product->filter('main')->text(), 'картка /en/: назва, опис, характеристики, опції, відгуки');

        // обхід посилань /en/ з головної (сторінки, блог, рубрики, рішення): ніде «[en]» і кирилиці в контенті
        $queue = ['/en/'];
        $seen = [];
        while ([] !== $queue && \count($seen) < 80) {
            $url = array_shift($queue);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $crawler = $this->client->request('GET', $url);
            if (200 !== $this->client->getResponse()->getStatusCode()) {
                continue;
            }
            self::assertStringNotContainsString('[en]', (string) $this->client->getResponse()->getContent(), $url);
            // #demo-pack — банер фікстурної теми паку тестів (tests/store/pack-demo), не демо-дані
            $main = str_replace($crawler->filter('#demo-pack')->text(''), '', $crawler->filter('main')->text(''));
            self::assertDoesNotMatchRegularExpression('/[а-яА-ЯіІїЇєЄґҐ]/u', $main, $url);
            foreach ($crawler->filter('a[href^="/en/"]')->extract(['href']) as $href) {
                $path = (string) strtok($href, '?#');
                if (!preg_match('~^/en/(account|cart|checkout|search|login|register)~', $path)) {
                    $queue[] = $path;
                }
            }
        }
        self::assertGreaterThan(20, \count($seen), 'обхід /en/ дійшов до сторінок, блогу й каталогу');
    }

    /**
     * Повторний сід поверх даних старого сідера (позначка « [xx]», копія тексту мови за замовчуванням) оновлює переклади
     * наявних записів, а правлене в адмінці лишає.
     */
    private function assertStaleTranslationsRefreshed(): void
    {
        if (!\in_array('en', $this->locales(), true)) {
            return;
        }
        $door = (int) $this->demoProduct('DEMO-DV-P01')->getId();
        $hammer = (int) $this->demoProduct('DEMO-TL-H02')->getId();
        $uk = DemoTexts::products()['DV-P01']['name']['uk'];
        $this->db->executeStatement("UPDATE catalog_product_translation SET name = ?, slug = 'demo-legacy-door' WHERE product_id = ? AND locale = 'en'", [$uk.' [en]', $door]);
        $this->db->executeStatement("UPDATE catalog_product_translation SET name = 'Hammer edited in admin' WHERE product_id = ? AND locale = 'en'", [$hammer]);
        $about = DemoTexts::pages()['about'];
        $this->db->executeStatement(
            "UPDATE content_page_translation t JOIN content_page_translation d ON d.page_id = t.page_id AND d.locale = 'uk' AND d.title = ? SET t.title = ?, t.body = ? WHERE t.locale = 'en'",
            [$about['title']['uk'], $about['title']['uk'].' [en]', '<p>[en]</p>'.$about['body']['uk']],
        );
        $this->db->executeStatement("UPDATE catalog_category_translation SET name = ? WHERE locale = 'en' AND category_id = ?", [DemoTexts::categories()['dveri']['name']['uk'], (int) $this->demoCategory('uk', 'demo-dveri')->getId()]);
        $this->em->clear();

        $tester = $this->command();
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('translations_updated', $tester->getDisplay());

        self::assertSame(['Linea 1 door', 'linea-1-door'], array_values($this->db->fetchAssociative("SELECT name, slug FROM catalog_product_translation WHERE product_id = ? AND locale = 'en'", [$door]) ?: []));
        self::assertSame('Hammer edited in admin', $this->db->fetchOne("SELECT name FROM catalog_product_translation WHERE product_id = ? AND locale = 'en'", [$hammer]), 'правку з адмінки сід не перезаписує');
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM content_page_translation WHERE locale = 'en' AND title = 'About us' AND body NOT LIKE '%[en]%'"));
        self::assertSame('Doors', $this->db->fetchOne("SELECT name FROM catalog_category_translation WHERE locale = 'en' AND category_id = ?", [(int) $this->demoCategory('uk', 'demo-dveri')->getId()]));
        $this->assertNoPlaceholders();
        // новий слаг — з авто-301 зі старої адреси (на демо так і треба); тут прибираємо, бо purge редиректів не чіпає
        self::assertSame(1, $this->db->executeStatement("DELETE FROM seo_redirect WHERE source LIKE '%/demo-legacy-door'"));
    }

    /** Три демо-акції: −10 % на категорію «Інструмент», −300 на бренд Portalia, вимкнена на весь каталог. */
    private function assertDemoPromotions(): void
    {
        self::assertSame(3, $this->demoPromotions(), 'повторний сід акцій не дублює');
        $rows = $this->db->fetchAllAssociativeIndexed(
            'SELECT p.name, p.active, p.type, p.value, p.whole_catalog,
                    (SELECT COUNT(*) FROM pricing_promotion_category c WHERE c.promotion_id = p.id) AS categories,
                    (SELECT COUNT(*) FROM pricing_promotion_brand b WHERE b.promotion_id = p.id) AS brands
             FROM pricing_promotion p WHERE p.name LIKE ?',
            [DemoCatalog::PROMOTION_PREFIX.'%'],
        );
        $tools = $rows[DemoCatalog::PROMOTION_PREFIX.'інструмент −10 %'] ?? null;
        $doors = $rows[DemoCatalog::PROMOTION_PREFIX.'двері Portalia −300 грн'] ?? null;
        $all = $rows[DemoCatalog::PROMOTION_PREFIX.'весь каталог −5 % (вимкнена)'] ?? null;
        self::assertNotNull($tools);
        self::assertNotNull($doors);
        self::assertNotNull($all);
        self::assertSame(['1', 'percent', '1000', '0', '1', '0'], array_map('strval', [$tools['active'], $tools['type'], $tools['value'], $tools['whole_catalog'], $tools['categories'], $tools['brands']]));
        self::assertSame(['1', 'amount', '30000', '0', '0', '1'], array_map('strval', [$doors['active'], $doors['type'], $doors['value'], $doors['whole_catalog'], $doors['categories'], $doors['brands']]));
        self::assertSame(['0', 'percent', '500', '1', '0', '0'], array_map('strval', [$all['active'], $all['type'], $all['value'], $all['whole_catalog'], $all['categories'], $all['brands']]));
    }

    private function demoPromotions(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM pricing_promotion WHERE name LIKE ?', [DemoCatalog::PROMOTION_PREFIX.'%']);
    }

    private function command(): CommandTester
    {
        return new CommandTester((new Application(static::$kernel ?? self::bootKernel()))->find('app:demo:seed'));
    }

    /** @return list<string> */
    private function locales(): array
    {
        /** @var list<string> $locales */
        $locales = static::getContainer()->getParameter('app.locales');

        return $locales;
    }

    private function demoProducts(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(DISTINCT product_id) FROM catalog_product_variant WHERE sku LIKE ?', [DemoCatalog::SKU_PREFIX.'%']);
    }

    private function demoCategories(): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(DISTINCT category_id) FROM catalog_category_translation WHERE slug LIKE 'demo-%'");
    }

    /** @return list<string> */
    private function demoOrderStates(): array
    {
        $states = array_map('strval', $this->db->fetchFirstColumn("SELECT status FROM sales_order WHERE comment LIKE 'DEMO #%' AND phone LIKE '+3800000000__' AND email LIKE '%@example.com'"));
        sort($states);

        return $states;
    }

    private function demoProduct(string $sku): Product
    {
        $variant = $this->em->getRepository(ProductVariant::class)->findOneBy(['sku' => $sku]);
        $variant ??= $this->em->createQueryBuilder()->select('v')->from(ProductVariant::class, 'v')->where('v.sku LIKE :p')
            ->setParameter('p', $sku.'-%')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(ProductVariant::class, $variant, $sku);

        return $variant->getProduct();
    }

    private function demoCategory(string $locale, string $slug): Category
    {
        $translation = $this->em->getRepository(CategoryTranslation::class)->findOneBy(['locale' => $locale, 'slug' => $slug]);
        self::assertNotNull($translation, $slug);

        return $translation->getCategory();
    }
}
