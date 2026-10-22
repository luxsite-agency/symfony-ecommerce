<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Admin\Search\SmartSearchRepository;
use App\Core\B2b\Entity\Company;
use App\Core\B2b\Entity\CompanyMember;
use App\Core\Catalog\Entity\Brand;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\Solution;
use App\Core\Content\Entity\SolutionGroup;
use App\Core\Content\Entity\SolutionOption;
use App\Core\Customer\Entity\Customer;
use App\Core\Order\Entity\Order;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Core\Security\Enum\UserRole;
use App\Core\Shared\Money\Money;
use App\Tests\Support\CommittedCatalog;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Orm\EntityRepositoryInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Розумний пошук адмінки (рішення власника 20.10.2026): поле під заголовком розділу; товари — назва будь-якою мовою, SKU і
 * штрихкод за префіксом, бренд, усі слова (AND) у будь-якому полі; замовлення — номер («123» → …-000123), телефон у будь-якому
 * записі (+380, 0…, пробіли, дужки, AU +61), email, ім'я, компанія; інша розкладка — друга спроба з підказкою; точний збіг
 * (SKU, штрихкод, номер) — одразу картка; «Знайдено N». Товари комітяться (FULLTEXT не бачить незакомічених рядків).
 */
final class AdminSmartSearchTest extends AdminWebTestCase
{
    private const int P1 = CommittedCatalog::BASE_ID + 1;
    private const int P2 = CommittedCatalog::BASE_ID + 2;
    private const int P3 = CommittedCatalog::BASE_ID + 3;
    private const int P4 = CommittedCatalog::BASE_ID + 4;
    private const int P5 = CommittedCatalog::BASE_ID + 5;
    private const int BRAND = CommittedCatalog::BASE_ID + 1;

    protected function tearDown(): void
    {
        CommittedCatalog::purge($this->em()->getConnection(), [self::BRAND]);
        parent::tearDown();
    }

    public function testProductsByNameInAnyLanguageSkuBarcodeBrandAndAllWords(): void
    {
        $this->seedProducts();
        $this->loginAs(UserRole::Manager);

        self::assertSame([self::P1, self::P2], $this->found('/product', 'аспірин'), 'назва uk (FULLTEXT з префіксом)');
        self::assertSame([self::P1, self::P2], $this->found('/product', '  ASPIRIN   '), 'назва en, регістр і пробіли');
        self::assertSame([self::P1, self::P2], $this->found('/product', 'аспір'), 'префікс слова');
        self::assertSame([self::P1], $this->found('/product', 'aspirin 500'), 'усі слова — AND');
        self::assertSame([self::P2], $this->found('/product', 'aspirin zentiva'), 'слова в різних полях: назва + бренд');
        self::assertSame([], $this->found('/product', 'aspirin 750'));
        self::assertSame([self::P2, self::P3], $this->found('/product', 'zentiva'), 'бренд');
        self::assertSame([self::P1, self::P2], $this->found('/product', 'zqx-10'), 'SKU за префіксом');
        self::assertSame([self::P3], $this->found('/product', '48200001234'), 'штрихкод за префіксом');
        self::assertSame([self::P3], $this->found('/product', '123456'), 'кінець штрихкоду');
        self::assertSame([self::P3], $this->found('/product', 'no'), 'коротке слово — LIKE по назві (Noshpa)');

        $crawler = $this->client->request('GET', $this->adminUrl('/product?query=aspirin'));
        self::assertSame('Знайдено: 2', trim($crawler->filter('[data-search-found]')->text()));
        self::assertSame('["aspirin"]', $crawler->filter('[data-search-terms]')->attr('data-search-terms'), 'слова для підсвітки');
    }

    /**
     * Баг з проду 20.10.2026 (B2B-магазин, en): після #103 адмінка не знаходила шматок SKU («quote» з RCK-QUOTE), середину слова
     * («кладськ»), стоп-слова FULLTEXT («the», «for») — шукала FULLTEXT за початком слова і SKU лише з початку.
     */
    public function testProductsBySubstringOfSkuAndNameIncludingHyphensAndStopwords(): void
    {
        $this->seedRacks();
        $this->loginAs(UserRole::Manager);

        self::assertSame([self::P4], $this->found('/product', 'quote'), 'шматок SKU після дефіса');
        self::assertSame([self::P4, self::P5], $this->found('/product', 'RCK-'), 'SKU з дефісом, префікс');
        self::assertSame([self::P4], $this->found('/product', 'rck-quo'), 'SKU з дефісом, без регістру');
        self::assertSame([self::P4], $this->found('/product', 'RCK-QUOTE', ['exact' => '0']), 'SKU повністю — список з exact=0');
        self::assertSame([self::P5], $this->found('/product', 'racking-2'), 'кінець SKU');
        self::assertSame([self::P4], $this->found('/product', 'складськ'), 'кирилиця, префікс');
        self::assertSame([self::P4], $this->found('/product', 'кладськ'), 'кирилиця, середина слова');
        self::assertSame([self::P4], $this->found('/product', 'розрахунок системи'), 'кирилиця, два слова');
        self::assertSame([self::P4], $this->found('/product', 'Warehouse'), 'латиниця, назва en');
        self::assertSame([self::P4], $this->found('/product', 'the'), 'стоп-слово InnoDB');
        self::assertSame([self::P4], $this->found('/product', 'for warehouse'), 'стоп-слово + слово — AND');
        self::assertSame([self::P5], $this->found('/product', 'rackline'), 'бренд');
        self::assertSame([self::P5], $this->found('/product', 'rackline стелаж'), 'бренд + назва');
        self::assertSame([], $this->found('/product', 'quote стелаж'));
    }

    /**
     * Форма під заголовком сабмітиться так, як її сабмітить браузер (поля форми, без query string з action): запит, сортування
     * і фільтри доходять до списку, сторінка скидається на першу; поле й «Фільтри» — в одному рядку; без (i) у «Товарах».
     */
    public function testSearchFormSubmitKeepsSortAndFiltersAndSitsInOneRowWithFilters(): void
    {
        $this->seedRacks();
        $this->loginAs(UserRole::Manager);

        $crawler = $this->client->request('GET', $this->adminUrl('/product?'.http_build_query(['sort' => ['id' => 'DESC'], 'filters' => ['active' => '0']])));
        self::assertResponseIsSuccessful();
        $bar = $crawler->filter('#main .admin-search-bar');
        self::assertCount(1, $bar->filter('form.form-action-search input[name="query"]'), 'поле — у рядку пошуку');
        self::assertCount(1, $bar->filter('.datagrid-filters .action-filters-button'), 'фільтри — у тому самому рядку');
        self::assertCount(0, $crawler->filter('.content-header-help'), 'без (i): що шукається, каже placeholder');
        self::assertStringContainsString('.admin-search-bar .admin-search .form-group { width: 100%; max-width: none; margin: 0; padding: 0; }', (string) $this->client->getResponse()->getContent(), 'padding .form-group EasyAdmin обнулено');
        self::assertStringContainsString('.content-search-label { display: block; width: 100%; margin: 0; padding: 0; }', (string) $this->client->getResponse()->getContent(), 'і label');

        $this->client->submit($bar->filter('form.form-action-search')->form(['query' => 'rck-']));
        self::assertResponseIsSuccessful();
        $query = [];
        parse_str((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_QUERY), $query);
        self::assertSame('rck-', $query['query'] ?? null);
        self::assertSame(['id' => 'DESC'], $query['sort'] ?? null, 'сортування');
        self::assertSame(['active' => '0'], $query['filters'] ?? null, 'фільтри');
        self::assertSame('1', $query['page'] ?? '1', 'перша сторінка');
        self::assertSame([self::P4], self::ids($this->client->getCrawler()), 'пошук і фільтр «неактивні» разом');
    }

    public function testSmartSearchDecoratesEasyAdminRepository(): void
    {
        self::assertInstanceOf(SmartSearchRepository::class, static::getContainer()->get(EntityRepositoryInterface::class));
    }

    public function testExactSkuOrBarcodeOpensTheProductCard(): void
    {
        $this->seedProducts();
        $this->loginAs(UserRole::Manager);

        $this->client->request('GET', $this->adminUrl('/product?query=ZQX-1001'));
        self::assertResponseRedirects();
        self::assertStringContainsString('/product/'.self::P1, (string) $this->client->getResponse()->headers->get('Location'));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Показати всі результати', $crawler->filter('.alert')->text());

        $this->client->request('GET', $this->adminUrl('/product?query=4820000123456'));
        self::assertStringContainsString('/product/'.self::P3, (string) $this->client->getResponse()->headers->get('Location'), 'штрихкод');

        self::assertSame([self::P1], $this->found('/product', 'ZQX-1001', ['exact' => '0']), 'exact=0 — список');
    }

    public function testWrongKeyboardLayoutIsRetriedWithSuggestion(): void
    {
        $this->seedProducts();
        $this->loginAs(UserRole::Manager);

        // «аспірин», набране на англійській розкладці
        $crawler = $this->client->request('GET', $this->adminUrl('/product?query=fcgshby'));
        self::assertSame([self::P1, self::P2], self::ids($crawler));
        self::assertStringContainsString('«аспірин»', $crawler->filter('[data-search-suggestion]')->text());
        self::assertSame('["аспірин"]', $crawler->filter('[data-search-terms]')->attr('data-search-terms'), 'підсвітка — виправленого');

        // «aspirin» на українській розкладці
        self::assertSame([self::P1, self::P2], $this->found('/product', 'фізшкшт'));
        // і в інших розділах — типовий пошук EasyAdmin теж отримує другу спробу
        $category = new Category();
        $category->translate('uk')->setName('Вітаміни')->setSlug('vitaminy-tst');
        $this->em()->persist($category);
        $this->em()->flush();
        self::assertSame([(int) $category->getId()], $this->found('/category', 'dsnfvsyb'));
    }

    public function testOrdersByNumberPhoneEmailNameAndCompany(): void
    {
        [$olena, $ivan, $acme] = $this->seedOrders();
        $this->loginAs(UserRole::Manager);

        foreach (['050 111 22 33', '(050) 111-22-33', '+380501112233', '0501112233', '111-22-33'] as $phone) {
            self::assertSame([$olena->getId()], $this->found('/order', $phone), 'телефон: '.$phone);
        }
        self::assertSame([$ivan->getId()], $this->found('/order', '0412 345 678'), 'AU: 04… → +61 4…');
        self::assertSame([$ivan->getId()], $this->found('/order', '+61 412 345 678'));
        self::assertSame([$olena->getId()], $this->found('/order', 'olena'), 'імʼя');
        self::assertSame([$olena->getId()], $this->found('/order', 'OLENA@EXAMPLE'), 'email без регістру');
        self::assertSame([$olena->getId()], $this->found('/order', 'olena 111 22 33'), 'імʼя + телефон — AND');
        self::assertSame([], $this->found('/order', 'olena ivan'));
        self::assertSame([$acme->getId()], $this->found('/order', 'acme'), 'компанія покупця');
    }

    public function testOrderNumberWithoutPrefixOpensTheCard(): void
    {
        [$olena] = $this->seedOrders();
        $this->loginAs(UserRole::Manager);
        $number = $olena->getNumber();
        $short = (string) (int) substr($number, strrpos($number, '-') + 1);

        foreach ([$number, $short] as $query) {
            $this->client->request('GET', $this->adminUrl('/order?query='.rawurlencode($query)));
            self::assertResponseRedirects(null, 302, $query);
            self::assertStringContainsString('/order/'.$olena->getId(), (string) $this->client->getResponse()->headers->get('Location'), $query);
        }
        self::assertContains($olena->getId(), $this->found('/order', $short, ['exact' => '0']));
    }

    public function testSearchBarIsUnderTheTitleInEverySectionAndCountsResults(): void
    {
        $brand = new Brand('Tstbrandx', 'tstbrandx');
        $page = new Page();
        $page->translate('uk')->setTitle('Доставка і оплата tst')->setSlug('dostavka-tst');
        $this->em()->persist($brand);
        $this->em()->persist($page);
        $this->em()->flush();
        $this->loginAs(UserRole::Admin);

        foreach (['/product', '/category', '/brand', '/attribute', '/library', '/order', '/promotion', '/product-review', '/wholesale-application', '/company',
            '/customer-group', '/page', '/post', '/post-category', '/redirect', '/not-found', '/admin-audit', '/changeset', '/admin-api-token',
            '/ai-usage', '/user', '/setting', '/solution', '/solution-group'] as $path) {
            $crawler = $this->client->request('GET', $this->adminUrl($path.'?query=zzqqxx'));
            self::assertResponseIsSuccessful($path);
            self::assertCount(1, $crawler->filter('#main .admin-search-bar input[name="query"]'), $path.': поле під заголовком');
            self::assertCount(0, $crawler->filter('.content-top .form-action-search'), $path.': не у верхній панелі');
            self::assertSame('Знайдено: 0', trim($crawler->filter('[data-search-found]')->count() > 0 ? $crawler->filter('[data-search-found]')->text() : 'немає лічильника'), $path);
        }
        self::assertSame([(int) $brand->getId()], $this->found('/brand', 'tstbrand'));
        self::assertSame([(int) $page->getId()], $this->found('/page', 'доставка tst'), 'сторінка: усі слова');
        $placeholder = $this->client->request('GET', $this->adminUrl('/order?_locale=en'))->filter('.admin-search-bar input[name="query"]')->attr('placeholder');
        self::assertSame('Order number, phone, email, name or company', $placeholder, 'підказка розділу мовою адмінки');
        foreach (['uk' => "Номер, телефон, email, ім'я або компанія", 'ru' => 'Номер, телефон, email, имя или компания'] as $locale => $start) {
            $placeholder = (string) $this->client->request('GET', $this->adminUrl('/order?_locale='.$locale))->filter('.admin-search-bar input[name="query"]')->attr('placeholder');
            self::assertSame($start, $placeholder, 'підказка розділу кирилицею ('.$locale.'), не «Пошук»');
        }
    }

    public function testSolutionsByTitleGroupAndProductName(): void
    {
        $product = new Product('SOL-SRCH-1', 'UAH');
        $product->translate('uk')->setName('Ламінат Дуб Соларіс');
        $group = new SolutionGroup();
        $group->translate('uk')->setTitle('Підлога');
        $solution = new Solution();
        $solution->translate('uk')->setTitle('Ремонт кухні')->setIntro('Що покласти на підлогу');
        $solution->setGroup($group);
        $solution->addOption(new SolutionOption(null, $product));
        $other = new Solution();
        $other->translate('uk')->setTitle('Ванна кімната')->setIntro('Плитка');
        foreach ([$product, $group, $solution, $other] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->loginAs(UserRole::Admin);

        self::assertSame([(int) $solution->getId()], $this->found('/solution', 'кухні'), 'назва');
        self::assertSame([(int) $solution->getId()], $this->found('/solution', 'соларіс'), 'товар рішення');
        self::assertSame([(int) $solution->getId()], $this->found('/solution', 'підлога кухні'), 'група + назва — AND');
        self::assertSame([(int) $group->getId()], $this->found('/solution-group', 'gslkjuf'), 'група, інша розкладка');
    }

    /**
     * @param array<string, string> $extra
     *
     * @return list<int>
     */
    private function found(string $path, string $query, array $extra = []): array
    {
        $crawler = $this->client->request('GET', $this->adminUrl($path.'?'.http_build_query(['query' => $query] + $extra)));
        self::assertResponseIsSuccessful($path.'?query='.$query);

        return self::ids($crawler);
    }

    /** @return list<int> */
    private static function ids(Crawler $crawler): array
    {
        $ids = array_map(intval(...), $crawler->filter('table.datagrid tbody tr[data-id]')->each(static fn (Crawler $tr): string => (string) $tr->attr('data-id')));
        sort($ids);

        return $ids;
    }

    private function seedProducts(): void
    {
        CommittedCatalog::insert($this->em()->getConnection(), [
            self::P1 => ['names' => ['uk' => 'Аспірин 500 мг', 'en' => 'Aspirin 500 mg'], 'sku' => 'ZQX-1001'],
            self::P2 => ['names' => ['uk' => 'Аспірин Юніор 250', 'en' => 'Aspirin Junior 250'], 'sku' => 'ZQX-1002', 'brand' => self::BRAND],
            self::P3 => ['names' => ['uk' => 'Но-шпа', 'en' => 'Noshpa'], 'sku' => 'ZQY-2001', 'barcode' => '4820000123456', 'brand' => self::BRAND, 'active' => false],
        ], [self::BRAND => 'Zentiva']);
    }

    private function seedRacks(): void
    {
        CommittedCatalog::insert($this->em()->getConnection(), [
            self::P4 => ['names' => ['uk' => 'Розрахунок складської системи', 'en' => 'The quote for a warehouse system'], 'sku' => 'RCK-QUOTE', 'active' => false],
            self::P5 => ['names' => ['uk' => 'Стелаж паллетний', 'en' => 'Pallet racking'], 'sku' => 'RCK-RACKING-2', 'brand' => self::BRAND],
        ], [self::BRAND => 'Rackline']);
    }

    /** @return array{Order, Order, Order} */
    private function seedOrders(): array
    {
        $product = new Product('ORD-SRCH-1', 'UAH');
        $product->translate('uk')->setName('Товар для пошуку');
        $product->getDefaultVariant()->setPrice(Money::of(10_000, 'UAH'));
        $product->getDefaultVariant()->setStock('100');
        $customer = new Customer('buyer@acme.test', 'Petro Buyer', 'uk');
        $company = new Company('Acme Trading', '12345678', false, 'Petro Buyer', null);
        $this->em()->persist($product);
        $this->em()->persist($customer);
        $this->em()->persist($company);
        $this->em()->persist(new CompanyMember($company, $customer));
        $this->em()->flush();

        $place = static::getContainer()->get(PlaceOrder::class);
        $line = [['variant' => $product->getDefaultVariant(), 'quantity' => '1']];
        $olena = $place->place(new PlaceOrderRequest(OrderSource::Quick, 'uk', 'Olena Testova', '0501112233', $line, 'olena@example.test'));
        $ivan = $place->place(new PlaceOrderRequest(OrderSource::Quick, 'en', 'Ivan Petrenko', '+61 412 345 678', $line, 'ivan@mail.test'));
        $acme = $place->place(new PlaceOrderRequest(OrderSource::Quick, 'uk', 'Petro Buyer', '0679998877', $line, 'buyer@acme.test'));
        $acme->setCustomer($customer, null);
        $this->em()->flush();

        return [$olena, $ivan, $acme];
    }
}
