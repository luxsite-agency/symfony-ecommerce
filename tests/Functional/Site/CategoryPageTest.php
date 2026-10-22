<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Query\CatalogFilter;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use App\Site\Controller\CategoryPageController;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Сторінка категорії: лістинг піддерева з read-index, фасети з лічильниками, GET-фільтри за схемою ListingParams,
 * пагінація з canonical/prev/next, hreflang по app.locales, 404/400.
 */
final class CategoryPageTest extends CatalogPageTestCase
{
    protected function tearDown(): void
    {
        // Settings кешує всі значення в cache.app (файловий, живе між тестами), а DAMA відкочує лише БД
        static::getContainer()->get(Settings::class)->invalidate();
        parent::tearDown();
    }

    public function testCategoryRendersInEveryLocaleWithHreflang(): void
    {
        foreach (self::locales() as $locale) {
            $crawler = $this->get($this->categoryUrl($this->fx->sneakers, $locale));
            self::assertResponseIsSuccessful($locale);
            self::assertSame($locale.' sneakers', $crawler->filter('h1')->text());
            self::assertSame(self::hreflangCode($locale), $crawler->filter('html')->attr('lang'));
            // P1 (running — підкатегорія), P2, P3; P4 вимкнено
            self::assertSame(['P3', 'P1', 'P2'], $this->cardNames($crawler, $locale), 'ціна за зростанням');
            self::assertStringContainsString($locale.' running', $crawler->filter('.subcats')->text());

            self::assertSame($this->categoryUrl($this->fx->sneakers, $locale, [], UrlGeneratorInterface::ABSOLUTE_URL), $crawler->filter('link[rel="canonical"]')->attr('href'));
            self::assertCount(0, $crawler->filter('meta[name="robots"]'), 'сторінка без фільтрів індексується');
            $expected = self::expectedHreflang(fn (string $l): string => $this->categoryUrl($this->fx->sneakers, $l, [], UrlGeneratorInterface::ABSOLUTE_URL));
            self::assertEquals($expected, self::hreflang($crawler));
        }
    }

    /** A–Z (app.catalog_letters у тестах увімкнено): назви «<мова> P1…» — усі на літеру мови; інша літера — порожньо, але рядок лишається. */
    public function testLettersRowFiltersByFirstLetterOfName(): void
    {
        $locale = self::defaultLocale();
        $first = mb_strtoupper(mb_substr($locale, 0, 1));
        $crawler = $this->get($this->categoryUrl($this->fx->shoes, $locale));
        self::assertResponseIsSuccessful();
        self::assertCount(26, $crawler->filter('.az a, .az span'), 'латинська літера → повний A–Z');
        self::assertSame($first, $crawler->filter('.az a')->text(), 'посилання лише на літеру з картками');
        self::assertCount(25, $crawler->filter('.az span[aria-disabled]'));
        $all = $this->cardNames($crawler, $locale);

        $crawler = $this->get($this->categoryUrl($this->fx->shoes, $locale, ['letter' => mb_strtolower($first)]));
        self::assertResponseIsSuccessful();
        self::assertSame($all, $this->cardNames($crawler, $locale), 'усі назви на цю літеру');
        self::assertSame($first, $crawler->filter('.az a.current')->text());
        self::assertStringNotContainsString('letter=', (string) $crawler->filter('.az a.current')->attr('href'), 'клік по обраній знімає');
        self::assertCount(1, $crawler->filter('meta[name="robots"][content*="noindex"]'), 'літера — фільтр');

        $crawler = $this->get($this->categoryUrl($this->fx->shoes, $locale, ['letter' => 'z']));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->cardNames($crawler, $locale));
        self::assertSame('Z', $crawler->filter('.az a.current')->text(), 'обрана літера без карток лишається в рядку');
        self::assertSame($first, $crawler->filter('.az a:not(.current)')->text(), 'лічильники — без власного фільтра літери');
        self::assertCount(1, $crawler->filter('.facets a.reset'));
    }

    /** SEO п. 10: окремий H1 категорії на сторінці; назва лишається в title, крихтах і меню. */
    public function testCategoryH1FromTranslationElseName(): void
    {
        $locale = self::defaultLocale();
        $this->fx->sneakers->translate($locale)->setH1('Кросівки для бігу й міста');
        $this->em()->flush();

        $crawler = $this->get($this->categoryUrl($this->fx->sneakers, $locale));
        self::assertResponseIsSuccessful();
        self::assertSame('Кросівки для бігу й міста', $crawler->filter('h1')->text());
        self::assertStringStartsWith($locale.' sneakers', $crawler->filter('title')->text(), 'title — назва (або metaTitle), не H1');

        $crawler = $this->get($this->categoryUrl($this->fx->running, $locale));
        self::assertSame($locale.' running', $crawler->filter('h1')->text(), 'без H1 — назва');
    }

    public function testCardShowsPriceBrandAndAvailability(): void
    {
        $locale = self::defaultLocale();
        $crawler = $this->get($this->categoryUrl($this->fx->shoes, $locale));
        self::assertResponseIsSuccessful();

        $p6 = $this->card($crawler, 'P6');
        self::assertStringContainsString(Money::of(3000, 'UAH')->format($locale), $p6->filter('.price')->text());
        self::assertStringContainsString('Nike '.$this->suffix, $p6->text());
        self::assertCount(1, $p6->filter('.stock-in_stock'));
        self::assertSame($this->productUrl($this->fx->p['P6'], $locale), $p6->filter('h3 a')->attr('href'));

        self::assertCount(1, $this->card($crawler, 'P3')->filter('.stock-to_order'), 'V5 під замовлення');
    }

    public function testOptionFilterChangesListAndCounts(): void
    {
        $this->setting(SettingKeys::CATALOG_FACET_COUNTS_ENABLED, '1');
        $locale = self::defaultLocale();
        $size = $this->fx->size->getCode();
        $color = $this->fx->color->getCode();

        $before = $this->get($this->categoryUrl($this->fx->sneakers, $locale));
        self::assertSame('3', $before->filter('.found')->attr('data-total'));
        self::assertSame(1, $this->facetCount($before, $color, 'red'), 'червоні, які можна купити: лише P1 (P3 V4 без залишку, P4 вимкнено)');
        self::assertSame(2, $this->facetCount($before, $color, 'blue'), 'P2, P3 (V5 під замовлення)');

        $crawler = $this->get($this->categoryUrl($this->fx->sneakers, $locale, ['f' => [$size => 's42']]));
        self::assertResponseIsSuccessful();
        self::assertSame(['P1', 'P2'], $this->cardNames($crawler, $locale));
        self::assertSame('2', $crawler->filter('.found')->attr('data-total'));
        self::assertSame(1, $this->facetCount($crawler, $color, 'red'), 'колір серед 42-х: P1');
        self::assertSame(1, $this->facetCount($crawler, $color, 'blue'), 'колір серед 42-х: P2 (P3 42-го нема)');
        self::assertSame(1, $this->facetCount($crawler, $size, 's38'), 'розмір рахується без власного фільтра');
        self::assertCount(1, $crawler->filter('[data-facet="'.$size.'"] a.selected'));

        // фільтровані сторінки — noindex, canonical на нормалізований URL, без hreflang
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame($this->categoryUrl($this->fx->sneakers, $locale, ['f' => [$size => 's42']], UrlGeneratorInterface::ABSOLUTE_URL), $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame([], self::hreflang($crawler));

        // клік по «red» додає значення іншого атрибута (AND), по «s38» — до того самого (OR)
        $redLink = $crawler->filter('[data-facet="'.$color.'"] a')->reduce(static fn (Crawler $a): bool => str_contains($a->text(), 'red'))->attr('href');
        self::assertSame(['P1'], $this->cardNames($this->get((string) $redLink), $locale));
        $s38Link = $crawler->filter('[data-facet="'.$size.'"] a')->reduce(static fn (Crawler $a): bool => str_contains($a->text(), 's38'))->attr('href');
        self::assertStringContainsString('s38,s42', (string) $s38Link, 'значення в канонічному порядку');
        self::assertSame(['P1', 'P2'], $this->cardNames($this->get((string) $s38Link), $locale));
    }

    public function testCountsAreHiddenByDefaultButValuesStay(): void
    {
        $locale = self::defaultLocale();
        $size = $this->fx->size->getCode();
        $color = $this->fx->color->getCode();

        $landing = $this->get($this->categoryUrl($this->fx->sneakers, $locale));
        self::assertSame('3', $landing->filter('.found')->attr('data-total'), 'total рахується завжди');
        self::assertCount(0, $landing->filter('.facets .count'), 'рішення власника: за замовчуванням без цифр');
        self::assertSame(['red', 'blue'], $this->facetLabels($landing, $color, ['red', 'blue']));

        // з фільтром s42 колір серед 42-х — лише red (P1) і blue (P2), але значення без цифр не ховаються: стан «невідомо», не 0
        $filtered = $this->get($this->categoryUrl($this->fx->sneakers, $locale, ['f' => [$size => 's42']]));
        self::assertSame(['P1', 'P2'], $this->cardNames($filtered, $locale));
        self::assertCount(0, $filtered->filter('.facets .count'));
        self::assertSame(['s41', 's42', 's38'], $this->facetLabels($filtered, $size, ['s41', 's42', 's38']), 'усі розміри категорії, що можна купити (s40 — лише в непридбаного V4)');
        self::assertSame([], $this->facetLabels($filtered, $size, ['s40']));
    }

    public function testCountsOverThresholdAreUnknownButCategoryBaseCountsStay(): void
    {
        $this->setting(SettingKeys::CATALOG_FACET_COUNTS_ENABLED, '1');
        $this->setting(SettingKeys::CATALOG_FACET_COUNTS_MAX_SET, '1');
        $locale = self::defaultLocale();
        $size = $this->fx->size->getCode();
        $color = $this->fx->color->getCode();

        $landing = $this->get($this->categoryUrl($this->fx->sneakers, $locale));
        self::assertSame(2, $this->facetCount($landing, $color, 'blue'), 'без фільтрів — зі зведення категорії, поріг не діє');

        // знайдено 2 > N=1: необрані фасети без цифр; розмір (єдиний обраний) рахується над F0 — зі зведення, точно
        $filtered = $this->get($this->categoryUrl($this->fx->sneakers, $locale, ['f' => [$size => 's42']]));
        self::assertCount(0, $filtered->filter('[data-facet="'.$color.'"] .count'));
        self::assertSame(['red', 'blue'], $this->facetLabels($filtered, $color, ['red', 'blue']));
        self::assertSame(1, $this->facetCount($filtered, $size, 's38'));
        self::assertCount(0, $filtered->filter('[data-facet="in_stock"] .count'), '«в наявності» з фільтром — теж понад N');
    }

    public function testBrandPriceBooleanNumberAndStockFilters(): void
    {
        $locale = self::defaultLocale();
        $shoes = $this->fx->shoes;

        self::assertSame(['P3', 'P1', 'P6'], $this->cardNames($this->get($this->categoryUrl($shoes, $locale, ['brand' => 'nike-'.$this->suffix])), $locale));
        self::assertSame(['P3', 'P1'], $this->cardNames($this->get($this->categoryUrl($shoes, $locale, ['price' => ['max' => '10']])), $locale), 'ціни фікстури в копійках: P3 9.50 (V5), P1 10.00');
        self::assertSame(['P1'], $this->cardNames($this->get($this->categoryUrl($shoes, $locale, ['f' => [$this->fx->waterproof->getCode() => '1']])), $locale));
        self::assertSame(['P2'], $this->cardNames($this->get($this->categoryUrl($shoes, $locale, ['f' => [$this->fx->weight->getCode() => ['min' => '1']]])), $locale));
        $inStock = $this->get($this->categoryUrl($shoes, $locale, ['in_stock' => '1']));
        self::assertSame(['P1', 'P2', 'P6'], $this->cardNames($inStock, $locale));
        self::assertCount(1, $inStock->filter('[data-facet="in_stock"] a.selected'));

        // бренд-фасет рахується без власного фільтра; діапазони — одна форма панелі з поточними фільтрами у hidden і однією кнопкою
        $crawler = $this->get($this->categoryUrl($shoes, $locale, ['brand' => 'nike-'.$this->suffix, 'price' => ['min' => '5']]));
        self::assertStringContainsString('Puma '.$this->suffix, $crawler->filter('[data-facet="brand"]')->text());
        $form = $crawler->filter('.facets form.facets-form');
        self::assertCount(1, $form);
        self::assertSame('nike-'.$this->suffix, $form->filter('input[type="hidden"][name="brand"]')->attr('value'));
        self::assertCount(0, $form->filter('input[type="hidden"][name^="price"]'), 'ціна — видимими полями, не hidden');
        self::assertSame('5', $form->filter('[data-facet="price"] input[name="price[min]"]')->attr('value'));
        self::assertCount(1, $form->filter('button[type="submit"]'), 'одна кнопка на всі діапазони');
    }

    public function testUnknownParamsAreDroppedFromCanonicalAndLinks(): void
    {
        $locale = self::defaultLocale();
        $crawler = $this->get($this->categoryUrl($this->fx->sneakers, $locale, ['f' => ['nosuch' => 'x', $this->fx->size->getCode() => 'nosuch,s42'], 'brand' => 'nosuch-brand', 'utm_source' => 'x']));
        self::assertResponseIsSuccessful();
        self::assertSame(['P1', 'P2'], $this->cardNames($crawler, $locale));
        self::assertSame($this->categoryUrl($this->fx->sneakers, $locale, ['f' => [$this->fx->size->getCode() => 's42']], UrlGeneratorInterface::ABSOLUTE_URL), $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testTooManyAttributesOrBadPageIsBadRequest(): void
    {
        $locale = self::defaultLocale();
        $f = [];
        for ($i = 0; $i <= CatalogFilter::MAX_ATTRIBUTES; ++$i) {
            $f['a'.$i] = 'x';
        }
        $this->get($this->categoryUrl($this->fx->shoes, $locale, ['f' => $f]));
        self::assertResponseStatusCodeSame(400);

        $this->get($this->categoryUrl($this->fx->shoes, $locale, ['page' => 'abc']));
        self::assertResponseStatusCodeSame(400);
    }

    public function testNotFound(): void
    {
        $locale = self::defaultLocale();
        $this->get($this->url('site_category', ['path' => 'no-such-category-'.$this->suffix, '_locale' => $locale]));
        self::assertResponseStatusCodeSame(404);

        $this->fx->sneakers->setActive(false);
        $this->em()->flush();
        $this->get($this->categoryUrl($this->fx->running, $locale));
        self::assertResponseStatusCodeSame(404, 'вимкнений предок ховає піддерево');

        $this->get($this->categoryUrl($this->fx->shoes, $locale, ['page' => '2']));
        self::assertResponseStatusCodeSame(404, 'сторінка за межами');

        $locales = self::locales();
        if (\count($locales) > 1) {
            // слаг іншої мови під префіксом цієї — 404, не підміна мови
            $this->get($this->url('site_category', ['path' => $this->categorySlug($this->fx->shoes, $locales[1]), '_locale' => $locales[0]]));
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testEmptyCategoryAndEmptyFilterResultAre200(): void
    {
        $locale = self::defaultLocale();
        $empty = new Category();
        $empty->translate($locale)->setName('empty')->setSlug('empty-'.$this->suffix);
        $this->em()->persist($empty);
        $this->em()->flush();

        $crawler = $this->get($this->url('site_category', ['path' => 'empty-'.$this->suffix, '_locale' => $locale]));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.card'));
        self::assertCount(1, $crawler->filter('.empty'));

        $crawler = $this->get($this->categoryUrl($this->fx->bags, $locale, ['price' => ['min' => '999999']]));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.card'));
        self::assertCount(1, $crawler->filter('.empty'));
        self::assertCount(0, $crawler->filter('.sorts'), 'нічого сортувати — кнопок сортування нема');
        self::assertCount(1, $crawler->filter('.empty-state a.btn'), 'поруч із текстом — «Скинути фільтри»');
        self::assertSame($crawler->filter('.facets a.reset')->attr('href'), $crawler->filter('.empty-state a.btn')->attr('href'));
    }

    public function testPaginationWithCanonicalPrevNext(): void
    {
        $locale = self::defaultLocale();
        $category = new Category();
        $this->em()->persist($category);
        $ids = [];
        $products = [];
        for ($i = 1; $i <= CategoryPageController::PER_PAGE + 6; ++$i) {
            $product = new Product(\sprintf('BULK-%s-%02d', $this->suffix, $i), 'UAH');
            $product->addCategory($category);
            $product->getDefaultVariant()->setPrice(Money::of(100 + $i, 'UAH'));
            $product->getDefaultVariant()->setStock('1');
            $this->translateProduct($product, \sprintf('B%02d', $i));
            $this->em()->persist($product);
            $products[] = $product;
        }
        $category->translate($locale)->setName('bulk')->setSlug('bulk-'.$this->suffix);
        $this->em()->flush();
        foreach ($products as $product) {
            $ids[] = (int) $product->getId();
        }
        $this->reindex($ids);

        $first = $this->get($this->url('site_category', ['path' => 'bulk-'.$this->suffix, '_locale' => $locale]));
        self::assertCount(CategoryPageController::PER_PAGE, $first->filter('.card'));
        $page2 = $this->url('site_category', ['path' => 'bulk-'.$this->suffix, '_locale' => $locale, 'page' => '2'], UrlGeneratorInterface::ABSOLUTE_URL);
        self::assertSame($page2, $first->filter('link[rel="next"]')->attr('href'));
        self::assertCount(0, $first->filter('link[rel="prev"]'));
        self::assertStringEndsWith('page=2', (string) $first->filter('.pager a')->reduce(static fn (Crawler $a): bool => '2' === trim($a->text()))->attr('href'));
        self::assertSame('1', $first->filter('.pager [aria-current="page"]')->text());

        $second = $this->get($page2);
        self::assertResponseIsSuccessful();
        self::assertCount(6, $second->filter('.card'));
        self::assertSame($page2, $second->filter('link[rel="canonical"]')->attr('href'), 'сторінка пагінації — canonical на себе');
        self::assertSame($this->url('site_category', ['path' => 'bulk-'.$this->suffix, '_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL), $second->filter('link[rel="prev"]')->attr('href'), 'page=1 не пишеться');
        self::assertCount(0, $second->filter('link[rel="next"]'));
        self::assertCount(0, $second->filter('meta[name="robots"]'));

        $this->get($this->url('site_category', ['path' => 'bulk-'.$this->suffix, '_locale' => $locale, 'page' => '3']));
        self::assertResponseStatusCodeSame(404);
    }

    /** @return list<string> назви карток без префікса мови, у порядку показу */
    private function cardNames(Crawler $crawler, string $locale): array
    {
        return $crawler->filter('.card h3')->each(static fn (Crawler $h): string => substr(trim($h->text()), \strlen($locale) + 1));
    }

    private function card(Crawler $crawler, string $name): Crawler
    {
        return $crawler->filter('.card[data-product-id="'.$this->fx->id($name).'"]');
    }

    private function setting(string $code, string $value): void
    {
        static::getContainer()->get(Settings::class)->set($code, $value);
    }

    /**
     * Які з $labels є серед значень фасета (у порядку $labels).
     *
     * @param list<string> $labels
     *
     * @return list<string>
     */
    private function facetLabels(Crawler $crawler, string $attributeCode, array $labels): array
    {
        $texts = $crawler->filter('[data-facet="'.$attributeCode.'"] li a')->each(static fn (Crawler $a): string => $a->text());

        return array_values(array_filter($labels, static fn (string $label): bool => [] !== array_filter($texts, static fn (string $t): bool => str_ends_with($t, $label))));
    }

    private function facetCount(Crawler $crawler, string $attributeCode, string $label): int
    {
        $item = $crawler->filter('[data-facet="'.$attributeCode.'"] li')->reduce(static fn (Crawler $li): bool => str_contains($li->filter('a')->text(), $label));
        if (0 === $item->count()) {
            return 0;
        }

        return (int) trim($item->filter('.count')->text(), '()');
    }
}
