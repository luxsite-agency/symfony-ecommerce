<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

use InvalidArgumentException;

/**
 * Запит лістингу (ADR Rev 9): категорія (разом із піддеревом — через catalog_filter_product_category), обрані
 * значення характеристик (AND між атрибутами, OR усередині), числові діапазони, boolean, бренди, ціна, «в наявності»,
 * сортування, сторінка або keyset-курсор. Нормалізує вхід: id — додатні цілі без дублів у стабільному порядку (ключ
 * кешу не залежить від порядку в URL), кількість атрибутів і значень обмежена — інакше GET-запит будує SQL із
 * сотнею JOIN.
 */
final readonly class CatalogFilter
{
    public const int MAX_ATTRIBUTES = 12;
    public const int MAX_VALUES_PER_ATTRIBUTE = 50;
    public const int MAX_PER_PAGE = 100;
    /** Стеля списку id з пошуку: далі обмежуємо результат, а не будуємо IN на десятки тисяч значень. */
    public const int MAX_PRODUCT_IDS = 2000;

    /** @var array<int, list<int>> attribute_id → option_id[] */
    public array $options;
    /** @var array<int, NumberRange> */
    public array $numbers;
    /** @var array<int, bool> */
    public array $booleans;
    /** @var list<int> */
    public array $brandIds;
    public PriceRange $price;
    public int $page;
    public int $perPage;
    /** @var array{int, int}|null */
    public ?array $after;
    /** @var list<int>|null null — без обмеження; список (і порожній теж) — лише ці товари (пошук /search) */
    public ?array $productIds;
    /** Перша літера назви (A–Z, нижній регістр, одна літера будь-якого алфавіту) мовою $locale; null — без фільтра */
    public ?string $letter;

    /**
     * @param array<int|string, array<int|string>> $options    attribute_id → option_id[]
     * @param array<int|string, NumberRange>       $numbers
     * @param array<int|string, bool>              $booleans
     * @param array<int|string>                    $brandIds
     * @param array{int, int}|null                 $after      keyset-курсор із CatalogResult::$nextCursor (замість page)
     * @param array<int|string>|null               $productIds лише ці товари (результат ProductSearch); [] — нічого не знайдено
     * @param string|null                          $letter     фільтр A–Z: перша літера назви мовою $locale
     * @param string                               $locale     мова назв для $letter і лічильників літер
     * @param bool                                 $letters    рахувати лічильники літер (A–Z) разом із фасетами
     */
    public function __construct(
        public ?int $categoryId = null,
        array $options = [],
        array $numbers = [],
        array $booleans = [],
        array $brandIds = [],
        ?PriceRange $price = null,
        public bool $inStockOnly = false,
        public CatalogSort $sort = CatalogSort::PriceAsc,
        int $page = 1,
        int $perPage = 24,
        ?array $after = null,
        ?array $productIds = null,
        ?string $letter = null,
        public string $locale = '',
        public bool $letters = false,
    ) {
        $normalized = [];
        foreach ($options as $attributeId => $optionIds) {
            $ids = self::ids($optionIds);
            if ([] !== $ids && (int) $attributeId > 0) {
                $normalized[(int) $attributeId] = \array_slice($ids, 0, self::MAX_VALUES_PER_ATTRIBUTE);
            }
        }
        ksort($normalized);
        $this->options = $normalized;

        $ranges = [];
        foreach ($numbers as $attributeId => $range) {
            if ((int) $attributeId > 0 && !$range->isEmpty()) {
                $ranges[(int) $attributeId] = $range;
            }
        }
        ksort($ranges);
        $this->numbers = $ranges;

        $flags = [];
        foreach ($booleans as $attributeId => $value) {
            if ((int) $attributeId > 0) {
                $flags[(int) $attributeId] = $value;
            }
        }
        ksort($flags);
        $this->booleans = $flags;

        if (\count($this->options) + \count($this->numbers) + \count($this->booleans) > self::MAX_ATTRIBUTES) {
            throw new InvalidArgumentException(\sprintf('Не більше %d характеристик у фільтрі', self::MAX_ATTRIBUTES));
        }

        $this->brandIds = \array_slice(self::ids($brandIds), 0, self::MAX_VALUES_PER_ATTRIBUTE);
        $this->price = $price ?? new PriceRange(null, null);
        $this->page = max(1, $page);
        $this->perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $this->after = $after;
        $this->productIds = null === $productIds ? null : \array_slice(self::ids($productIds), 0, self::MAX_PRODUCT_IDS);
        $letter = null === $letter ? null : mb_strtolower(trim($letter));
        $this->letter = null !== $letter && '' !== $this->locale && 1 === preg_match('/^\p{L}$/u', $letter) ? $letter : null;
    }

    /** Характеристика бере участь у фільтрі (будь-яким видом значення). */
    public function hasAttribute(int $attributeId): bool
    {
        return isset($this->options[$attributeId]) || isset($this->numbers[$attributeId]) || isset($this->booleans[$attributeId]);
    }

    /** Стабільний ключ для кешу: однакові фільтри з різним порядком параметрів дають однаковий ключ. */
    public function cacheKey(): string
    {
        return hash('xxh128', serialize([
            $this->categoryId, $this->options,
            array_map(static fn (NumberRange $r): array => [$r->min, $r->max], $this->numbers),
            $this->booleans, $this->brandIds, [$this->price->min, $this->price->max],
            $this->inStockOnly, $this->sort->value, $this->page, $this->perPage, $this->after, $this->productIds,
            $this->letter, $this->locale,
        ]));
    }

    /** Ключ для total і фасетів: той самий набір, але без сторінки, розміру сторінки, сортування й курсора. */
    public function facetKey(): string
    {
        return hash('xxh128', serialize([
            $this->categoryId, $this->options,
            array_map(static fn (NumberRange $r): array => [$r->min, $r->max], $this->numbers),
            $this->booleans, $this->brandIds, [$this->price->min, $this->price->max], $this->inStockOnly, $this->productIds,
            $this->letter, $this->locale, $this->letters,
        ]));
    }

    /**
     * @param array<int|string> $ids
     *
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(intval(...), $ids), static fn (int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }
}
