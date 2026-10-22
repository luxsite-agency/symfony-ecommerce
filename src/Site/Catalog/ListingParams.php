<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Catalog;

use App\Core\Catalog\Query\CatalogFilter;
use App\Core\Catalog\Query\CatalogSort;
use App\Core\Catalog\Query\NumberRange;
use InvalidArgumentException;

/**
 * GET-схема лістингу категорії (стабільна, коди замість id — URL переживає перенесення БД):
 *
 *   f[<attr>]=<opt>,<opt>        select/multiselect: коди значень через кому (OR усередині, AND між атрибутами)
 *   f[<attr>]=1|0                boolean
 *   f[<attr>][min]=…&[max]=…     число (крапка як роздільник; будь-яка межа може бути відсутня)
 *   brand=<slug>,<slug>          бренди
 *   price[min]=…&price[max]=…    ціна в основних одиницях валюти магазину
 *   in_stock=1                   лише в наявності
 *   letter=a                     A–Z: перша літера назви (одна літера, нижній регістр; app.catalog_letters паку)
 *   sort=price_desc|newest       price_asc — за замовчуванням, у URL не пишеться
 *   page=N                       N > 1; page=1 не пишеться
 *
 * toQuery() віддає параметри в канонічному порядку (атрибути й значення відсортовані), тож однаковий фільтр — один URL.
 * Синтаксично невалідне (сміттєві коди, нечислові межі) тихо відкидається; неіснуючі для категорії коди відкидає
 * ListingFilterFactory. Понад CatalogFilter::MAX_ATTRIBUTES характеристик або нечислова page — InvalidArgumentException
 * (контролер віддає 400).
 */
final readonly class ListingParams
{
    private const string CODE = '/^[a-z][a-z0-9_]{0,63}$/';
    private const string SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** @var array<string, list<string>> attr code → коди значень (або ['1']/['0'] для boolean) */
    public array $values;
    /** @var array<string, NumberRange> attr code → діапазон */
    public array $ranges;
    /** @var list<string> */
    public array $brands;
    public NumberRange $price;

    /**
     * @param array<string, list<string>> $values
     * @param array<string, NumberRange>  $ranges
     * @param list<string>                $brands
     */
    public function __construct(
        array $values = [],
        array $ranges = [],
        array $brands = [],
        ?NumberRange $price = null,
        public bool $inStock = false,
        public CatalogSort $sort = CatalogSort::PriceAsc,
        public int $page = 1,
        public ?string $letter = null,
    ) {
        $normalized = [];
        foreach ($values as $code => $list) {
            $list = self::sortedUnique($list);
            if ([] !== $list) {
                $normalized[$code] = $list;
            }
        }
        ksort($normalized);
        $this->values = $normalized;
        $ranges = array_filter($ranges, static fn (NumberRange $r): bool => !$r->isEmpty());
        ksort($ranges);
        $this->ranges = $ranges;
        $this->brands = self::sortedUnique($brands);
        $this->price = $price ?? new NumberRange(null, null);
        if (\count($this->values) + \count($this->ranges) > CatalogFilter::MAX_ATTRIBUTES) {
            throw new InvalidArgumentException(\sprintf('Не більше %d характеристик у фільтрі', CatalogFilter::MAX_ATTRIBUTES));
        }
    }

    /** @param array<array-key, mixed> $query $request->query->all() */
    public static function fromQuery(array $query): self
    {
        $values = $ranges = [];
        $f = $query['f'] ?? [];
        if (\is_array($f)) {
            foreach ($f as $code => $raw) {
                $code = (string) $code;
                if (!preg_match(self::CODE, $code)) {
                    continue;
                }
                if (\is_array($raw)) {
                    $ranges[$code] = new NumberRange(self::scalar($raw['min'] ?? null), self::scalar($raw['max'] ?? null));
                } elseif (\is_string($raw)) {
                    $values[$code] = array_values(array_filter(
                        explode(',', $raw),
                        static fn (string $v): bool => '0' === $v || '1' === $v || 1 === preg_match(self::CODE, $v),
                    ));
                }
            }
        }

        $brands = [];
        if (\is_string($query['brand'] ?? null)) {
            $brands = array_values(array_filter(explode(',', $query['brand']), static fn (string $s): bool => 1 === preg_match(self::SLUG, $s)));
        }
        $price = null;
        if (\is_array($query['price'] ?? null)) {
            $price = new NumberRange(self::scalar($query['price']['min'] ?? null), self::scalar($query['price']['max'] ?? null));
        }
        $sort = \is_string($query['sort'] ?? null) ? (CatalogSort::tryFrom($query['sort']) ?? CatalogSort::PriceAsc) : CatalogSort::PriceAsc;

        $page = 1;
        if (isset($query['page'])) {
            if (!\is_string($query['page']) || !preg_match('/^[1-9]\d{0,5}$/', $query['page'])) {
                throw new InvalidArgumentException('Номер сторінки — додатне ціле');
            }
            $page = (int) $query['page'];
        }

        $letter = \is_string($query['letter'] ?? null) ? mb_strtolower(trim($query['letter'])) : null;

        return new self($values, $ranges, $brands, $price, '1' === ($query['in_stock'] ?? null), $sort, $page, null !== $letter && 1 === preg_match('/^\p{L}$/u', $letter) ? $letter : null);
    }

    /** @return array<string, mixed> канонічний порядок; значення за замовчуванням не пишуться */
    public function toQuery(): array
    {
        $query = [];
        $codes = array_unique(array_merge(array_keys($this->values), array_keys($this->ranges)));
        sort($codes);
        foreach ($codes as $code) {
            if (isset($this->values[$code])) {
                $query['f'][$code] = implode(',', $this->values[$code]);
            } elseif (isset($this->ranges[$code])) {
                $query['f'][$code] = self::range($this->ranges[$code]);
            }
        }
        if ([] !== $this->brands) {
            $query['brand'] = implode(',', $this->brands);
        }
        if (!$this->price->isEmpty()) {
            $query['price'] = self::range($this->price);
        }
        if ($this->inStock) {
            $query['in_stock'] = '1';
        }
        if (null !== $this->letter) {
            $query['letter'] = $this->letter;
        }
        if (CatalogSort::PriceAsc !== $this->sort) {
            $query['sort'] = $this->sort->value;
        }
        if ($this->page > 1) {
            $query['page'] = (string) $this->page;
        }

        return $query;
    }

    /** Є хоч один фільтр (сортування і сторінка — не фільтри). */
    public function hasFilters(): bool
    {
        return [] !== $this->values || [] !== $this->ranges || [] !== $this->brands || !$this->price->isEmpty() || $this->inStock || null !== $this->letter;
    }

    /** A–Z: літера (null — зняти); сторінка скидається. */
    public function withLetter(?string $letter): self
    {
        return new self($this->values, $this->ranges, $this->brands, $this->price, $this->inStock, $this->sort, 1, $letter);
    }

    public function isValueSelected(string $code, string $value): bool
    {
        return \in_array($value, $this->values[$code] ?? [], true);
    }

    /** Увімкнути/вимкнути значення (сторінка скидається: інший набір — інша пагінація). */
    public function toggleValue(string $code, string $value): self
    {
        $values = $this->values;
        $list = $values[$code] ?? [];
        $values[$code] = \in_array($value, $list, true) ? array_values(array_diff($list, [$value])) : [...$list, $value];

        return $this->with(values: $values);
    }

    /** Boolean: одне значення на атрибут; повторний клік по обраному знімає фільтр. */
    public function toggleFlag(string $code, bool $flag): self
    {
        $values = $this->values;
        $wanted = $flag ? '1' : '0';
        if ([$wanted] === ($values[$code] ?? null)) {
            unset($values[$code]);
        } else {
            $values[$code] = [$wanted];
        }

        return $this->with(values: $values);
    }

    public function withoutAttribute(string $code): self
    {
        $values = $this->values;
        $ranges = $this->ranges;
        unset($values[$code], $ranges[$code]);

        return $this->with(values: $values, ranges: $ranges);
    }

    public function toggleBrand(string $slug): self
    {
        $brands = \in_array($slug, $this->brands, true) ? array_values(array_diff($this->brands, [$slug])) : [...$this->brands, $slug];

        return $this->with(brands: $brands);
    }

    public function withoutBrands(): self
    {
        return $this->with(brands: []);
    }

    public function withoutPrice(): self
    {
        return $this->with(price: new NumberRange(null, null));
    }

    public function toggleInStock(): self
    {
        return $this->with(inStock: !$this->inStock);
    }

    public function withSort(CatalogSort $sort): self
    {
        return $this->with(sort: $sort);
    }

    public function withPage(int $page): self
    {
        return $this->with(page: max(1, $page));
    }

    /** Лише сортування (для «скинути фільтри»). */
    public function withoutFilters(): self
    {
        return new self(sort: $this->sort);
    }

    /**
     * Приховані поля спільної форми діапазонів панелі фільтрів (UX-аудит S10: одна кнопка на всі діапазони):
     * поточні параметри без page, ціни й усіх числових діапазонів — їх форма надсилає своїми полями.
     *
     * @return array<string, string>
     */
    public function rangeFormFields(): array
    {
        return self::flatten($this->withPage(1)->withoutPrice()->with(ranges: [])->toQuery());
    }

    /**
     * Поточні параметри як пари name → value для прихованих полів GET-форми діапазону (без page і без самого поля).
     *
     * @return array<string, string>
     */
    public function hiddenFields(?string $exceptAttribute = null, bool $exceptPrice = false): array
    {
        $params = $this->withPage(1);
        if (null !== $exceptAttribute) {
            $params = $params->withoutAttribute($exceptAttribute);
        }
        if ($exceptPrice) {
            $params = $params->withoutPrice();
        }

        return self::flatten($params->toQuery());
    }

    /**
     * @param array<string, list<string>>|null $values
     * @param array<string, NumberRange>|null  $ranges
     * @param list<string>|null                $brands
     */
    private function with(?array $values = null, ?array $ranges = null, ?array $brands = null, ?NumberRange $price = null, ?bool $inStock = null, ?CatalogSort $sort = null, int $page = 1): self
    {
        return new self($values ?? $this->values, $ranges ?? $this->ranges, $brands ?? $this->brands, $price ?? $this->price, $inStock ?? $this->inStock, $sort ?? $this->sort, $page, $this->letter);
    }

    /** @return array{min?: string, max?: string} */
    private static function range(NumberRange $range): array
    {
        return array_filter(['min' => $range->min, 'max' => $range->max], static fn (?string $v): bool => null !== $v);
    }

    /**
     * @param array<array-key, mixed> $query
     *
     * @return array<string, string>
     */
    private static function flatten(array $query, string $prefix = ''): array
    {
        $fields = [];
        foreach ($query as $key => $value) {
            $name = '' === $prefix ? (string) $key : $prefix.'['.$key.']';
            if (\is_array($value)) {
                $fields += self::flatten($value, $name);
            } elseif (\is_scalar($value)) {
                $fields[$name] = (string) $value;
            }
        }

        return $fields;
    }

    private static function scalar(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? $value : null;
    }

    /**
     * @param array<mixed> $list
     *
     * @return list<string>
     */
    private static function sortedUnique(array $list): array
    {
        $strings = array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '', $list);
        $strings = array_values(array_unique(array_filter($strings, static fn (string $v): bool => '' !== $v)));
        sort($strings, \SORT_STRING);

        return $strings;
    }
}
