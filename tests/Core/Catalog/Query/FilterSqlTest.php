<?php

declare(strict_types=1);

namespace App\Tests\Core\Catalog\Query;

use App\Core\Catalog\Enum\AttributeType;
use App\Core\Catalog\Query\CatalogFilter;
use App\Core\Catalog\Query\FacetDefinition;
use App\Core\Catalog\Query\FilterSql;
use App\Core\Catalog\Query\NumberRange;
use App\Core\Catalog\Query\PriceRange;
use PHPUnit\Framework\TestCase;

/** Правила наявності й exclude у базі запиту (ADR Rev 9) — без БД, по згенерованому SQL. */
final class FilterSqlTest extends TestCase
{
    private const int SIZE = 5;     // вісь
    private const int MATERIAL = 8; // карткова
    private const int WEIGHT = 9;   // число

    public function testCardAttributesOnlyUseListedAndCategoryEquality(): void
    {
        [$sql, $params] = $this->sql(new CatalogFilter(17, [self::MATERIAL => [25]]))->base();

        self::assertStringContainsString('pc.category_id = :category', $sql);
        self::assertStringContainsString('JOIN catalog_filter_value f8 ON f8.variant_id = v.variant_id AND f8.attribute_id = 8 AND f8.option_id IN (:o8)', $sql, 'без leadOrder порядок — оптимізатору');
        self::assertStringEndsWith('v.listed = 1', $sql);
        self::assertSame(17, $params['category']);
        self::assertSame([25], $params['o8']);
    }

    public function testLeadIsMostSelectiveNotExcludedOptionAttributeAndNoOptionsKeepCategoryFirst(): void
    {
        $filter = new CatalogFilter(1, [self::SIZE => [10], self::MATERIAL => [25]]);
        $sql = new FilterSql($filter, $this->definitions(), [self::MATERIAL, self::SIZE]);
        self::assertStringStartsWith(' FROM catalog_filter_value f8 STRAIGHT_JOIN', $sql->base()[0]);
        self::assertStringContainsString(' JOIN catalog_filter_value f5 ON f5.variant_id = v.variant_id AND f5.attribute_id = 5 AND f5.option_id IN (:o5)', $sql->base()[0]);
        self::assertStringContainsString('WHERE pc.category_id = :category AND f8.attribute_id = 8 AND f8.option_id IN (:o8)', $sql->base()[0]);
        self::assertStringStartsWith(' FROM catalog_filter_value f5 STRAIGHT_JOIN', $sql->base(FilterSql::excludeAttribute(self::MATERIAL))[0], 'ведуча виключена — наступна');

        $numberOnly = $this->sql(new CatalogFilter(1, numbers: [self::WEIGHT => new NumberRange('1', null)]))->base()[0];
        self::assertStringStartsWith(' FROM catalog_filter_product_category pc JOIN catalog_filter_variant v', $numberOnly);
        self::assertStringStartsWith(' FROM catalog_filter_product_category pc STRAIGHT_JOIN catalog_filter_variant v', $this->sql(new CatalogFilter(1))->base()[0]);
    }

    public function testAxisOrPriceMakesBasePurchasableAndInStockWins(): void
    {
        self::assertStringEndsWith('v.purchasable = 1', $this->sql(new CatalogFilter(1, [self::SIZE => [10]]))->base()[0]);
        $price = $this->sql(new CatalogFilter(1, price: new PriceRange(100, null)))->base()[0];
        self::assertStringContainsString('v.price_mode IN (0, 1) AND v.price_minor >= :pmin', $price);
        self::assertStringEndsWith('v.purchasable = 1', $price);
        self::assertStringEndsWith('v.in_stock = 1', $this->sql(new CatalogFilter(1, [self::SIZE => [10]], inStockOnly: true))->base()[0]);
    }

    public function testLetterJoinsTranslationAndLeavesCategoryBaseOnlyWhenExcluded(): void
    {
        $sql = $this->sql(new CatalogFilter(1, letter: 'A', locale: 'uk'));
        [$base, $params] = $sql->base();
        self::assertStringContainsString(' JOIN catalog_product_translation tl ON tl.product_id = v.product_id AND tl.locale = :locale AND tl.name LIKE :letter', $base);
        self::assertSame(['locale' => 'uk', 'letter' => 'a%'], array_intersect_key($params, ['locale' => 1, 'letter' => 1]));
        self::assertFalse($sql->isCategoryBase(), 'літера — фільтр, F0-шлях не годиться');
        self::assertTrue($sql->isCategoryBase(FilterSql::EXCLUDE_LETTER), 'лічильники літер над базою без літери — зі зведення');
        self::assertStringNotContainsString('catalog_product_translation', $sql->base(FilterSql::EXCLUDE_LETTER)[0]);
        self::assertStringContainsString(' JOIN x ON 1 WHERE', $sql->base(FilterSql::EXCLUDE_LETTER, false, ' JOIN x ON 1')[0], 'extraJoin для лічильників літер');

        self::assertNull((new CatalogFilter(1, letter: 'ab', locale: 'uk'))->letter);
        self::assertNull((new CatalogFilter(1, letter: 'a'))->letter, 'без мови літеру застосувати нема до чого');
        self::assertNull((new CatalogFilter(1, letter: '%', locale: 'uk'))->letter);
        self::assertNotSame((new CatalogFilter(1, locale: 'uk'))->facetKey(), (new CatalogFilter(1, locale: 'uk', letters: true))->facetKey(), 'лічильники літер — інший запис кешу');
    }

    public function testProductIdsRestrictBaseAndEmptyListMatchesNothing(): void
    {
        [$sql, $params] = $this->sql(new CatalogFilter(null, productIds: [7, 3, 7]))->base();
        self::assertStringStartsWith(' FROM catalog_filter_variant v WHERE v.product_id IN (:ids)', $sql, 'пошук без категорії');
        self::assertSame([3, 7], $params['ids']);
        self::assertStringEndsWith('v.listed = 1', $sql);

        [$sql] = $this->sql(new CatalogFilter(null, productIds: []))->base();
        self::assertStringContainsString('WHERE 0 = 1 AND', $sql, 'нічого не знайдено — порожній результат без обходу індексу');

        [$sql, $params] = $this->sql(new CatalogFilter(1, [self::MATERIAL => [25]], productIds: [5]))->base(FilterSql::excludeAttribute(self::MATERIAL));
        self::assertStringContainsString('v.product_id IN (:ids)', $sql, 'обмеження id не виключається для фасетів');
        self::assertSame([5], $params['ids']);
    }

    public function testExcludeDropsOwnPredicateButForceVariantKeepsAxisSemantics(): void
    {
        $sql = $this->sql(new CatalogFilter(1, [self::SIZE => [10], self::MATERIAL => [25]]));

        [$withoutSize] = $sql->base(FilterSql::excludeAttribute(self::SIZE));
        self::assertStringNotContainsString(' f5 ', $withoutSize);
        self::assertStringContainsString(' f8 ', $withoutSize);
        self::assertStringEndsWith('v.listed = 1', $withoutSize, 'без осі база — listed…');
        self::assertStringEndsWith('v.purchasable = 1', $sql->base(FilterSql::excludeAttribute(self::SIZE), true)[0], '…але лічильник осі рахується по purchasable');
        self::assertStringEndsWith('v.purchasable = 1', $sql->base(FilterSql::excludeAttribute(self::MATERIAL))[0], 'вісь лишилась — purchasable');
    }

    public function testNumberBoundsAndUnknownAttributesIgnored(): void
    {
        $filter = new CatalogFilter(null, [999 => [1], self::WEIGHT => [3]], [self::WEIGHT => new NumberRange('1.5', null)], [self::MATERIAL => true]);
        $sql = $this->sql($filter);
        [$base, $params] = $sql->base();

        self::assertStringStartsWith(' FROM catalog_filter_variant v', $base);
        self::assertStringContainsString('f9.number_value >= :nmin9', $base);
        self::assertStringNotContainsString(':nmax9', $base);
        self::assertStringNotContainsString('f999', $base);
        self::assertStringNotContainsString('f8', $base, 'boolean для select-характеристики — не той тип');
        self::assertSame('1.5', $params['nmin9']);
        self::assertSame([self::WEIGHT], array_keys($sql->selected()));
        self::assertSame([self::SIZE, self::MATERIAL], array_keys($sql->unselected()));
    }

    private function sql(CatalogFilter $filter): FilterSql
    {
        return new FilterSql($filter, $this->definitions());
    }

    /** @return list<FacetDefinition> */
    private function definitions(): array
    {
        return [
            new FacetDefinition(self::SIZE, 'size', AttributeType::Select, true),
            new FacetDefinition(self::MATERIAL, 'material', AttributeType::MultiSelect, false),
            new FacetDefinition(self::WEIGHT, 'weight', AttributeType::Number, false, 'kg'),
        ];
    }
}
