<?php

declare(strict_types=1);

namespace App\Tests\Core\Catalog\Query;

use App\Core\Catalog\Query\CatalogFilter;
use App\Core\Catalog\Query\CatalogSort;
use App\Core\Catalog\Query\NumberRange;
use App\Core\Catalog\Query\PriceRange;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Нормалізація вхідного фільтра: стабільний ключ кешу, межі, сміття з URL. */
final class CatalogFilterTest extends TestCase
{
    public function testNormalizesIdsAndOrderForStableCacheKey(): void
    {
        $a = new CatalogFilter(5, [8 => ['25', 25, 3], 2 => [7]], brandIds: [3, '1', 3, -4, 0]);
        $b = new CatalogFilter(5, [2 => [7], 8 => [3, 25]], brandIds: [1, 3]);

        self::assertSame([2 => [7], 8 => [3, 25]], $a->options);
        self::assertSame([1, 3], $a->brandIds);
        self::assertSame($a->cacheKey(), $b->cacheKey());
        self::assertNotSame($a->cacheKey(), (new CatalogFilter(5, [2 => [7]]))->cacheKey());
    }

    public function testDropsEmptySelectionsAndClampsPaging(): void
    {
        $filter = new CatalogFilter(null, [4 => [], 0 => [1]], [9 => new NumberRange('abc', null)], page: -3, perPage: 1000);

        self::assertSame([], $filter->options);
        self::assertSame([], $filter->numbers, 'нечисловий діапазон з URL — як не заданий');
        self::assertSame(1, $filter->page);
        self::assertSame(CatalogFilter::MAX_PER_PAGE, $filter->perPage);
        self::assertTrue($filter->price->isEmpty());
        self::assertFalse($filter->hasAttribute(4));
    }

    public function testNumberRangeAcceptsDecimalComma(): void
    {
        $range = new NumberRange(' 1,5 ', '3');
        self::assertSame('1.5', $range->min);
        self::assertSame('3', $range->max);
        self::assertTrue((new PriceRange(null, null))->isEmpty());
    }

    public function testNumberRangeSwapsReversedBounds(): void
    {
        $range = new NumberRange('5600', '250');
        self::assertSame(['250', '5600'], [$range->min, $range->max], 'min > max з URL — межі міняються місцями, а не порожній результат');
        $open = new NumberRange('5600', null);
        self::assertSame(['5600', null], [$open->min, $open->max]);
    }

    public function testCapsValuesAndRejectsTooManyAttributes(): void
    {
        $filter = new CatalogFilter(1, [1 => range(1, 200)]);
        self::assertCount(CatalogFilter::MAX_VALUES_PER_ATTRIBUTE, $filter->options[1]);

        $this->expectException(InvalidArgumentException::class);
        new CatalogFilter(1, array_fill_keys(range(1, CatalogFilter::MAX_ATTRIBUTES + 1), [1]));
    }

    public function testProductIdsAreNormalizedCappedAndPartOfBothKeys(): void
    {
        $a = new CatalogFilter(null, productIds: ['9', 2, 2, 0, -1]);
        self::assertSame([2, 9], $a->productIds);
        self::assertNull((new CatalogFilter(null))->productIds);
        self::assertSame([], (new CatalogFilter(null, productIds: []))->productIds, 'порожній список ≠ null');
        self::assertCount(CatalogFilter::MAX_PRODUCT_IDS, (new CatalogFilter(null, productIds: range(1, CatalogFilter::MAX_PRODUCT_IDS + 50)))->productIds);

        $b = new CatalogFilter(null, productIds: [2, 9]);
        self::assertSame($a->cacheKey(), $b->cacheKey());
        self::assertNotSame((new CatalogFilter(null))->facetKey(), $a->facetKey(), 'total/фасети пошуку — окремий запис кешу');
        self::assertNotSame((new CatalogFilter(null, productIds: [2]))->cacheKey(), $a->cacheKey());
    }

    public function testFacetKeyIgnoresPagingAndSortButNotFilters(): void
    {
        $base = new CatalogFilter(5, [3 => [7]]);
        self::assertSame($base->facetKey(), (new CatalogFilter(5, [3 => [7]], sort: CatalogSort::Newest, page: 4, perPage: 48))->facetKey());
        self::assertSame($base->facetKey(), (new CatalogFilter(5, [3 => [7]], after: [100, 9]))->facetKey());
        self::assertNotSame($base->cacheKey(), (new CatalogFilter(5, [3 => [7]], page: 2))->cacheKey());
        self::assertNotSame($base->facetKey(), (new CatalogFilter(5, [3 => [8]]))->facetKey());
        self::assertNotSame($base->facetKey(), (new CatalogFilter(5, [3 => [7]], inStockOnly: true))->facetKey());
        self::assertNotSame($base->facetKey(), (new CatalogFilter(5, [3 => [7]], price: new PriceRange(1, null)))->facetKey());
    }
}
