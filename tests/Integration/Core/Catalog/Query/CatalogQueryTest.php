<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog\Query;

use App\Core\Catalog\FilterIndex\FilterIndexer;
use App\Core\Catalog\Query\CatalogCacheVersion;
use App\Core\Catalog\Query\CatalogFacets;
use App\Core\Catalog\Query\CatalogFilter;
use App\Core\Catalog\Query\CatalogHit;
use App\Core\Catalog\Query\CatalogQuery;
use App\Core\Catalog\Query\CatalogResult;
use App\Core\Catalog\Query\CatalogSort;
use App\Core\Catalog\Query\CategoryFacetResolver;
use App\Core\Catalog\Query\CategorySummaryStore;
use App\Core\Catalog\Query\FacetCounting;
use App\Core\Catalog\Query\FacetCountPolicy;
use App\Core\Catalog\Query\FacetDefinition;
use App\Core\Catalog\Query\ListingRateLimit;
use App\Core\Catalog\Query\NumberRange;
use App\Core\Catalog\Query\PriceRange;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use App\Tests\Integration\Core\Catalog\FilterIndex\CatalogIndexFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Лістинг і фасети над read-index (ADR Rev 9) на фікстурі CatalogIndexFixture. Лічильники — картки, не варіанти;
 * обраний атрибут рахується без власного фільтра (disjunctive).
 */
final class CatalogQueryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CatalogQuery $query;
    private CatalogIndexFixture $fx;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->query = $this->query();
        $this->fx = new CatalogIndexFixture($this->em, bin2hex(random_bytes(3)));
        self::getContainer()->get(FilterIndexer::class)->reindexProducts($this->fx->productIds());
        self::getContainer()->get(CatalogCacheVersion::class)->bump();
        // Фасетний блок усього дерева shoes — характеристики фікстури (інакше fallback підтягне filterable з інших тестів)
        foreach ([$this->fx->size, $this->fx->color, $this->fx->material, $this->fx->weight, $this->fx->waterproof] as $attribute) {
            $this->fx->shoes->addFilterAttribute($attribute);
        }
        $this->em->flush();
    }

    public function testCategoryListingIncludesSubtreeAndSkipsInactive(): void
    {
        $result = $this->list();
        self::assertSame(['P3', 'P1', 'P2', 'P6'], $this->names($result), 'ціна за зростанням: P3 від 900 (V4 є в лістингу, хоч і не купити)');
        self::assertSame(4, $result->total);
        self::assertSame(['P1', 'P2', 'P3'], $this->sortedNames($this->list(['categoryId' => $this->fx->sneakers->getId()])));
        self::assertSame(['P1'], $this->names($this->list(['categoryId' => $this->fx->running->getId()])));
    }

    public function testCriticExampleSameVariantAndDisjunctiveCounts(): void
    {
        $size = (int) $this->fx->size->getId();
        $color = (int) $this->fx->color->getId();
        $result = $this->list(['options' => [$size => [$this->fx->optionId('42')], $color => [$this->fx->optionId('red')]]]);

        self::assertSame(['P1'], $this->names($result), '42 і red на одному варіанті лише в P1 (P4 вимкнено)');
        self::assertSame([$this->fx->optionId('38') => 1, $this->fx->optionId('42') => 1], $result->facets->options[$size], 'розмір без власного фільтра: red-варіанти');
        self::assertSame([$this->fx->optionId('red') => 1, $this->fx->optionId('blue') => 1], $result->facets->options[$color], 'колір без власного фільтра: 42-варіанти');
    }

    public function testNotSameVariantDoesNotMatch(): void
    {
        // У P1 є 38 (червоний) і 42 (червоний), але 38 синього нема ні в кого
        $result = $this->list(['options' => [
            (int) $this->fx->size->getId() => [$this->fx->optionId('38')],
            (int) $this->fx->color->getId() => [$this->fx->optionId('blue')],
        ]]);
        self::assertSame([], $result->hits);
        self::assertSame(0, $result->total);
    }

    public function testUnselectedFacetsAxesCountPurchasableCardAttributesCountListed(): void
    {
        $facets = $this->list()->facets;
        $size = (int) $this->fx->size->getId();
        self::assertSame([$this->fx->optionId('41') => 1, $this->fx->optionId('42') => 2, $this->fx->optionId('38') => 1], $this->ordered($facets->options[$size], ['41', '42', '38']), '40 є лише в непридбаного V4');
        self::assertArrayNotHasKey($this->fx->optionId('40'), $facets->options[$size]);
        self::assertSame(3, $facets->options[(int) $this->fx->material->getId()][$this->fx->optionId('leather')], 'P1, P3, P6');
        self::assertSame(2, $facets->options[(int) $this->fx->material->getId()][$this->fx->optionId('mesh')]);
        self::assertSame([0 => 1, 1 => 1], $this->ksorted($facets->booleans[(int) $this->fx->waterproof->getId()]));
        self::assertEquals(new NumberRange('0.8000', '1.2000'), $facets->numbers[(int) $this->fx->weight->getId()]);
        self::assertSame([(int) $this->fx->nike->getId() => 3, (int) $this->fx->puma->getId() => 1], $this->ksorted($facets->brands));
        self::assertEquals(new PriceRange(950, 3000), $facets->price, 'лише purchasable: V4 за 900 не рахується');
        self::assertSame(3, $facets->inStock, 'P1, P2, P6');
        self::assertCount(5, $this->list()->definitions);
    }

    public function testMultiselectDoesNotDuplicateCards(): void
    {
        $result = $this->list(['options' => [(int) $this->fx->material->getId() => [$this->fx->optionId('leather'), $this->fx->optionId('mesh')]]]);
        self::assertSame(['P1', 'P2', 'P3', 'P6'], $this->sortedNames($result));
        self::assertSame(4, $result->total);
    }

    public function testNumberRangeAndItsOwnFacetIgnoresOwnFilter(): void
    {
        $weight = (int) $this->fx->weight->getId();
        $result = $this->list(['numbers' => [$weight => new NumberRange('1.0', '1,5')]]);
        self::assertSame(['P2'], $this->names($result));
        self::assertEquals(new NumberRange('0.8000', '1.2000'), $result->facets->numbers[$weight]);
    }

    public function testBooleanFilter(): void
    {
        $flag = (int) $this->fx->waterproof->getId();
        $result = $this->list(['booleans' => [$flag => true]]);
        self::assertSame(['P1'], $this->names($result));
        self::assertSame([0 => 1, 1 => 1], $this->ksorted($result->facets->booleans[$flag]));
        self::assertSame(['P2'], $this->names($this->list(['booleans' => [$flag => false]])));
    }

    public function testBrandFilterAndDisjunctiveBrandCounts(): void
    {
        $result = $this->list(['brandIds' => [(int) $this->fx->nike->getId()]]);
        self::assertSame(['P1', 'P3', 'P6'], $this->sortedNames($result));
        self::assertSame([(int) $this->fx->nike->getId() => 3, (int) $this->fx->puma->getId() => 1], $this->ksorted($result->facets->brands));
        self::assertSame(3, $result->facets->options[(int) $this->fx->material->getId()][$this->fx->optionId('leather')]);
    }

    public function testPriceRangeUsesPurchasableVariantsAndMinPriceOfMatchedVariants(): void
    {
        $result = $this->list(['price' => new PriceRange(950, 1300)]);
        self::assertSame(['P3', 'P1'], $this->names($result));
        self::assertSame([950, 1000], array_map(static fn (CatalogHit $h): int => $h->minPriceMinor, $result->hits), 'P3 — ціна саме V5 (V4 за 900 не купити)');
        self::assertEquals(new PriceRange(950, 3000), $result->facets->price, 'ціновий фасет — без власного фільтра');

        $only1200 = $this->list(['price' => new PriceRange(1100, null)]);
        self::assertSame(1200, $only1200->hits[0]->minPriceMinor, 'P1 від 1200: V1 за 1000 під фільтр не підпадає');
    }

    public function testInStockOnly(): void
    {
        $result = $this->list(['inStockOnly' => true]);
        self::assertSame(['P1', 'P2', 'P6'], $this->sortedNames($result));
        self::assertSame(3, $result->facets->inStock);
        self::assertSame([$this->fx->optionId('42') => 2, $this->fx->optionId('38') => 1], $this->ordered($result->facets->options[(int) $this->fx->size->getId()], ['42', '38']));
    }

    public function testOnRequestPriceIsExcludedFromPriceFilterAndSortedLast(): void
    {
        $bags = $this->list(['categoryId' => $this->fx->bags->getId()]);
        self::assertSame(['P5'], $this->names($bags));
        self::assertTrue($bags->hits[0]->isOnRequest());
        self::assertNull($bags->facets->price);
        self::assertSame([], $this->list(['categoryId' => $this->fx->bags->getId(), 'price' => new PriceRange(0, null)])->hits);

        // У спільній категорії «за запитом» — у кінці і при зростанні, і при спаданні ціни
        $this->fx->p['P5']->addCategory($this->fx->shoes);
        $this->em->flush();
        self::getContainer()->get(FilterIndexer::class)->reindexProducts([$this->fx->id('P5')]);
        self::assertSame(['P3', 'P1', 'P2', 'P6', 'P5'], $this->names($this->list()));
        self::assertSame(['P6', 'P2', 'P1', 'P3', 'P5'], $this->names($this->list(['sort' => CatalogSort::PriceDesc])));
    }

    public function testSortingAndPagination(): void
    {
        self::assertSame(['P6', 'P2', 'P1', 'P3'], $this->names($this->list(['sort' => CatalogSort::PriceDesc])));
        self::assertSame(['P6', 'P3', 'P2', 'P1'], $this->names($this->list(['sort' => CatalogSort::Newest])));

        $first = $this->list(['perPage' => 2]);
        self::assertSame(['P3', 'P1'], $this->names($first));
        self::assertSame(4, $first->total);
        self::assertSame(2, $first->pageCount());
        self::assertNotNull($first->nextCursor);

        $second = $this->list(['perPage' => 2, 'page' => 2]);
        self::assertSame(['P2', 'P6'], $this->names($second));
        self::assertNull($second->nextCursor);

        $keyset = $this->list(['perPage' => 2, 'after' => $first->nextCursor]);
        self::assertSame(['P2', 'P6'], $this->names($keyset));

        $desc = $this->list(['perPage' => 3, 'sort' => CatalogSort::PriceDesc]);
        self::assertSame(['P3'], $this->names($this->list(['perPage' => 3, 'sort' => CatalogSort::PriceDesc, 'after' => $desc->nextCursor])));
    }

    public function testUnknownOrMistypedAttributesAreIgnored(): void
    {
        $result = $this->list(['options' => [(int) $this->fx->hidden->getId() => [$this->fx->optionId('secret')], (int) $this->fx->weight->getId() => [1]]]);
        self::assertSame(4, $result->total);
    }

    public function testSearchIsCachedUntilVersionBump(): void
    {
        $filter = new CatalogFilter($this->fx->shoes->getId());
        self::assertSame(4, $this->query->search($filter)->total);

        $this->fx->p['P6']->setActive(false);
        $this->em->flush(); // точкове оновлення індексу — версію кешу не міняє
        self::assertSame(4, $this->query->search($filter)->total, 'у межах TTL — кеш');
        self::assertSame(3, $this->query->execute($filter)->total);

        self::getContainer()->get(CatalogCacheVersion::class)->bump();
        self::assertSame(3, $this->query->search($filter)->total);
    }

    public function testSearchSharesTotalAndFacetsAcrossPages(): void
    {
        $page1 = $this->query->search(new CatalogFilter($this->fx->shoes->getId(), perPage: 2));
        $this->fx->p['P6']->setActive(false);
        $this->em->flush(); // точкове оновлення індексу — версію кешу не міняє

        $page2 = $this->query->search(new CatalogFilter($this->fx->shoes->getId(), page: 2, perPage: 2));
        self::assertSame(4, $page2->total, 'total і фасети сторінки 2 — із запису кешу сторінки 1 (ключ без page)');
        self::assertEquals($page1->facets, $page2->facets);
        self::assertSame(2, $page2->page);
        self::assertNotSame($page1->productIds(), $page2->productIds());
    }

    public function testFacetResolverInheritsNearestListAndFallsBackToAllFilterable(): void
    {
        $resolver = $this->resolver();
        $codes = static fn (array $defs): array => array_map(static fn (FacetDefinition $d): string => $d->code, $defs);
        $size = $this->fx->size->getCode();

        self::assertSame($codes($resolver->load($this->fx->shoes->getId())), $codes($resolver->load($this->fx->running->getId())), 'успадковано від shoes');

        $this->fx->sneakers->addFilterAttribute($this->fx->color);
        $this->fx->sneakers->addFilterAttribute($this->fx->size);
        $this->em->flush();
        self::assertSame([$this->fx->color->getCode(), $size], $codes($resolver->load($this->fx->running->getId())), 'найближчий предок зі списком, у його порядку');

        $all = $codes($resolver->load($this->fx->bags->getId()));
        self::assertContains($this->fx->weight->getCode(), $all, 'без списку — усі filterable');
        self::assertNotContains($this->fx->hidden->getCode(), $all);

        $this->fx->size->setFilterable(false);
        $this->em->flush();
        self::assertSame([$this->fx->color->getCode()], $codes($resolver->load($this->fx->sneakers->getId())), 'не filterable — зникає зі списку');
    }

    public function testCountsDisabledKeepValuesOfCategoryWithoutNumbers(): void
    {
        $off = new FacetCounting(false, 20000);
        $color = (int) $this->fx->color->getId();
        $landing = $this->list(counting: $off);
        self::assertSame(4, $landing->total);
        self::assertSame([$this->fx->optionId('red') => null, $this->fx->optionId('blue') => null], $this->ksortedNullable($landing->facets->options[$color]), 'значення категорії є, цифр нема');
        self::assertNull($landing->facets->inStock);
        self::assertSame([(int) $this->fx->nike->getId() => null, (int) $this->fx->puma->getId() => null], $this->ksortedNullable($landing->facets->brands));
        self::assertEquals(new PriceRange(950, 3000), $landing->facets->price, 'діапазони — не лічильники, лишаються');

        // з фільтром — ті самі значення категорії (не 0 і не сховані), хоча blue серед leather-карток має 1 (P3)
        $filtered = $this->list(['options' => [(int) $this->fx->material->getId() => [$this->fx->optionId('leather')]]], $off);
        self::assertSame(['P3', 'P1', 'P6'], $this->names($filtered));
        self::assertSame([$this->fx->optionId('red') => null, $this->fx->optionId('blue') => null], $this->ksortedNullable($filtered->facets->options[$color]));
        self::assertEquals(new NumberRange('0.8000', '0.8000'), $filtered->facets->numbers[(int) $this->fx->weight->getId()], 'вага leather-карток: лише P1');
    }

    public function testThresholdTurnsLargeBasesUnknownAndCategoryBaseComesFromSummary(): void
    {
        $material = (int) $this->fx->material->getId();
        $color = (int) $this->fx->color->getId();
        $leather = ['options' => [$material => [$this->fx->optionId('leather')]]];

        $small = $this->list($leather, new FacetCounting(true, 3));
        self::assertSame(3, $small->total);
        self::assertSame(1, $small->facets->optionCount($color, $this->fx->optionId('blue')), 'база 3 ≤ N — точний підрахунок');

        $large = $this->list($leather, new FacetCounting(true, 2));
        self::assertTrue($large->facets->hasOption($color, $this->fx->optionId('blue')));
        self::assertNull($large->facets->optionCount($color, $this->fx->optionId('blue')), 'база 3 > N — невідомо');
        self::assertNull($large->facets->inStock);
        self::assertSame(3, $large->facets->optionCount($material, $this->fx->optionId('leather')), 'єдиний обраний атрибут: база = F0 — зі зведення, поріг не діє');
        self::assertSame(2, $large->facets->optionCount($material, $this->fx->optionId('mesh')));
    }

    public function testSummaryIsReadWithoutTtlAndRefreshedAfterIndexWrite(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $store = self::getContainer()->get(CategorySummaryStore::class);
        $shoes = (int) $this->fx->shoes->getId();
        $this->list(); // зведення є (або пораховано зараз)
        $summary = $store->get($shoes);
        self::assertNotNull($summary);
        self::assertSame(4, $summary->total);

        // підмінене зведення читається як є: без TTL і без глобальної версії кешу
        $brands = [(int) $this->fx->nike->getId() => 99] + $summary->facets->brands;
        $store->save($shoes, $summary->total, new CatalogFacets($summary->facets->options, $summary->facets->booleans, $summary->facets->numbers, $brands, $summary->facets->price, $summary->facets->inStock));
        self::getContainer()->get(CatalogCacheVersion::class)->bump();
        self::assertSame(99, $this->list()->facets->brands[(int) $this->fx->nike->getId()]);

        // запис в індекс позначає категорії (з предками) у черзі; у тестах затримка 0 і обробка sync — зведення вже свіже
        self::getContainer()->get(FilterIndexer::class)->reindexProducts([$this->fx->id('P6')]);
        self::assertSame(3, $this->list()->facets->brands[(int) $this->fx->nike->getId()]);
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM catalog_filter_category_dirty WHERE category_id = ?', [$shoes]));
    }

    public function testOrderedLimitOneRangesMatchAggregates(): void
    {
        $ordered = $this->query(0); // LIMIT 1 для будь-якої бази
        $weight = (int) $this->fx->weight->getId();
        foreach ([
            [],
            ['options' => [(int) $this->fx->size->getId() => [$this->fx->optionId('42')]]],
            ['brandIds' => [(int) $this->fx->puma->getId()]],
            ['inStockOnly' => true],
            ['numbers' => [$weight => new NumberRange('1.0', null)]],
            ['price' => new PriceRange(1000, 2000)],
            ['categoryId' => $this->fx->bags->getId()],
        ] as $i => $args) {
            $aggregate = $this->list($args);
            $limitOne = $this->list($args, query: $ordered);
            self::assertEquals($aggregate->facets->numbers, $limitOne->facets->numbers, 'числа, випадок '.$i);
            self::assertEquals($aggregate->facets->price, $limitOne->facets->price, 'ціна, випадок '.$i);
        }
        self::assertEquals(new NumberRange('0.8000', '1.2000'), $this->list(['brandIds' => [(int) $this->fx->nike->getId(), (int) $this->fx->puma->getId()]], query: $ordered)->facets->numbers[$weight]);
    }

    public function testCategoryPageKeysFollowRepriceAndDeactivationButNotStock(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $keys = fn (string $name): array => array_map(intval(...), (array) $db->fetchAssociative(
            'SELECT listed, sort_asc, sort_desc FROM catalog_filter_product_category WHERE category_id = ? AND product_id = ?',
            [$this->fx->shoes->getId(), $this->fx->id($name)],
        ));
        self::assertSame(['listed' => 1, 'sort_asc' => 900, 'sort_desc' => 900], $keys('P3'), 'мін. ціна listed-варіантів (V4 не купити, але listed)');
        self::assertSame(['listed' => 0, 'sort_asc' => 2_147_483_647, 'sort_desc' => -1], $keys('P4'), 'товар вимкнено');

        $this->fx->v['V1']->setStock('0'); // лише залишок — ключі сторінки не змінюються
        $this->em->flush();
        self::assertSame(['listed' => 1, 'sort_asc' => 1000, 'sort_desc' => 1000], $keys('P1'));

        $this->fx->v['V1']->setPrice(Money::of(5000, 'UAH'));
        $this->fx->p['P2']->setActive(false);
        $this->em->flush();
        self::assertSame(['listed' => 1, 'sort_asc' => 1200, 'sort_desc' => 1200], $keys('P1'), 'репрайс V1 → мін. стала V2');
        self::assertSame(0, $keys('P2')['listed']);
        self::assertSame(['P3', 'P1', 'P6'], $this->names($this->list()));
        self::assertSame(['P6', 'P1', 'P3'], $this->names($this->list(['sort' => CatalogSort::PriceDesc])));
        self::assertSame(3, $this->list()->total);
    }

    public function testRateLimitDropsCountsAndSkipsCache(): void
    {
        $c = self::getContainer();
        $c->get(RequestStack::class)->push(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.'.random_int(1, 250)]));
        $factory = new RateLimiterFactory(['id' => 'catalog_test_'.bin2hex(random_bytes(3)), 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage());
        $limited = new CatalogQuery(
            $c->get(Connection::class), $this->resolver(), $c->get(CatalogCacheVersion::class), $c->get('cache.app'),
            $c->get(CategorySummaryStore::class), $c->get(FacetCountPolicy::class), new ListingRateLimit($c->get(RequestStack::class), $factory),
        );
        $c->get(Settings::class)->set(SettingKeys::CATALOG_FACET_COUNTS_ENABLED, '1');
        try {
            $color = (int) $this->fx->color->getId();
            $filter = new CatalogFilter($this->fx->shoes->getId(), options: [(int) $this->fx->material->getId() => [$this->fx->optionId('leather')]]);
            self::assertSame(2, $limited->search(new CatalogFilter($this->fx->shoes->getId()))->facets->optionCount($color, $this->fx->optionId('blue')), 'у межах ліміту: P2, P3');

            $degraded = $limited->search($filter);
            self::assertSame(3, $degraded->total, 'понад ліміт — сторінка й total є, не 429');
            self::assertSame(['P3', 'P1', 'P6'], $this->names($degraded));
            self::assertTrue($degraded->facets->hasOption($color, $this->fx->optionId('blue')));
            self::assertNull($degraded->facets->optionCount($color, $this->fx->optionId('blue')));

            self::assertSame(1, $this->query->search($filter)->facets->optionCount($color, $this->fx->optionId('blue')), 'урізаний результат не потрапив у кеш');
        } finally {
            $c->get(Settings::class)->invalidate();
            $c->get(RequestStack::class)->pop();
        }
    }

    /**
     * @param array<int, int|null> $counts
     *
     * @return array<int, int|null>
     */
    private function ksortedNullable(array $counts): array
    {
        ksort($counts);

        return $counts;
    }

    private function resolver(): CategoryFacetResolver
    {
        return new CategoryFacetResolver(self::getContainer()->get(Connection::class), self::getContainer()->get(CatalogCacheVersion::class), self::getContainer()->get('cache.app'));
    }

    /** Вручну — щоб задати поріг LIMIT 1-діапазонів (фікстура крихітна, дефолтний поріг туди не дістає). */
    private function query(int $orderedRangeMinBase = CatalogQuery::ORDERED_RANGE_MIN_BASE): CatalogQuery
    {
        $c = self::getContainer();

        return new CatalogQuery(
            $c->get(Connection::class), $this->resolver(), $c->get(CatalogCacheVersion::class), $c->get('cache.app'),
            $c->get(CategorySummaryStore::class), $c->get(FacetCountPolicy::class), $c->get(ListingRateLimit::class), $orderedRangeMinBase,
        );
    }

    /** @param array<string, mixed> $args */
    private function list(array $args = [], ?FacetCounting $counting = null, ?CatalogQuery $query = null): CatalogResult
    {
        $args += ['categoryId' => $this->fx->shoes->getId()];

        return ($query ?? $this->query)->execute(new CatalogFilter(...$args), counting: $counting ?? FacetCounting::exact());
    }

    /** @return list<string> */
    private function names(CatalogResult $result): array
    {
        $byId = [];
        foreach ($this->fx->p as $name => $product) {
            $byId[(int) $product->getId()] = $name;
        }

        return array_map(static fn (int $id): string => $byId[$id] ?? '#'.$id, $result->productIds());
    }

    /** @return list<string> */
    private function sortedNames(CatalogResult $result): array
    {
        $names = $this->names($result);
        sort($names);

        return $names;
    }

    /**
     * @param array<int, int> $counts
     * @param list<string>    $order  коди опцій фікстури
     *
     * @return array<int, int>
     */
    private function ordered(array $counts, array $order): array
    {
        $result = [];
        foreach ($order as $code) {
            $id = $this->fx->optionId($code);
            if (isset($counts[$id])) {
                $result[$id] = $counts[$id];
            }
        }
        self::assertCount(\count($counts), $result, 'зайві опції у фасеті');

        return $result;
    }

    /**
     * @param array<int, int> $counts
     *
     * @return array<int, int>
     */
    private function ksorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }
}
