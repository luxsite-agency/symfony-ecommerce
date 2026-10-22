<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Catalog;

use App\Core\Catalog\Query\CatalogFilter;
use App\Core\Catalog\Query\FacetDefinition;
use App\Core\Catalog\Query\PriceRange;

/**
 * GET-параметри (коди) → CatalogFilter (id) для фасетного блоку категорії. Коди, яких нема в блоці категорії, значення
 * не того типу й невідомі бренди відкидаються; повертається й «ефективний» ListingParams — лише те, що реально
 * застосовано (з нього будуються canonical, пагінація і посилання фасетів, тож сміття з URL далі не розмножується).
 */
final readonly class ListingFilterFactory
{
    /** Мінорних одиниць в основній (ціна в URL — у гривнях/доларах, у read-index — у копійках/центах). */
    public const int MINOR_PER_MAJOR = 100;

    public function __construct(private CatalogLabels $labels)
    {
    }

    /**
     * @param list<FacetDefinition>                                                                        $definitions
     * @param array<int, array{label: string, options: list<array{id: int, code: string, label: string}>}> $labels      CatalogLabels::attributes()
     * @param list<int>|null                                                                               $productIds  пошук: лише ці товари (null — категорія без обмеження)
     * @param string                                                                                       $locale      мова назв для A–Z ('' — літера з URL ігнорується)
     * @param bool                                                                                         $letters     рахувати лічильники A–Z (app.catalog_letters)
     *
     * @return array{CatalogFilter, ListingParams}
     */
    public function create(?int $categoryId, ListingParams $params, array $definitions, array $labels, int $perPage, ?array $productIds = null, string $locale = '', bool $letters = false): array
    {
        $options = $numbers = $booleans = [];
        $values = $ranges = [];
        foreach ($definitions as $definition) {
            $code = $definition->code;
            $id = $definition->attributeId;
            if ($definition->hasOptions() && isset($params->values[$code])) {
                $byCode = array_column($labels[$id]['options'] ?? [], 'id', 'code');
                foreach ($params->values[$code] as $optionCode) {
                    if (isset($byCode[$optionCode])) {
                        $options[$id][] = $byCode[$optionCode];
                        $values[$code][] = $optionCode;
                    }
                }
            } elseif ($definition->isBoolean() && \in_array($params->values[$code] ?? null, [['0'], ['1']], true)) {
                $booleans[$id] = ['1'] === $params->values[$code];
                $values[$code] = $params->values[$code];
            } elseif ($definition->isNumber() && isset($params->ranges[$code])) {
                $numbers[$id] = $params->ranges[$code];
                $ranges[$code] = $params->ranges[$code];
            }
        }

        $brandIds = $this->labels->brandIdsBySlugs($params->brands);
        $price = new PriceRange(self::minor($params->price->min, false), self::minor($params->price->max, true));
        $filter = new CatalogFilter(
            categoryId: $categoryId,
            options: $options,
            numbers: $numbers,
            booleans: $booleans,
            brandIds: array_values($brandIds),
            price: $price,
            inStockOnly: $params->inStock,
            sort: $params->sort,
            page: $params->page,
            perPage: $perPage,
            productIds: $productIds,
            letter: $params->letter,
            locale: $locale,
            letters: $letters,
        );
        $effective = new ListingParams(
            $values,
            $ranges,
            array_keys($brandIds),
            $price->isEmpty() ? null : $params->price,
            $params->inStock,
            $params->sort,
            $params->page,
            $filter->letter,
        );

        return [$filter, $effective];
    }

    /** Основні одиниці → мінорні; нижня межа — вниз, верхня — вгору (межа «до 99.999» не відрізає 99.99). */
    public static function minor(?string $major, bool $ceil): ?int
    {
        if (null === $major) {
            return null;
        }
        $value = (float) $major * self::MINOR_PER_MAJOR;
        $value = $ceil ? ceil($value - 1e-6) : floor($value + 1e-6);

        return (int) max(0, min($value, \PHP_INT_MAX / 2));
    }

    /** Мінорні одиниці → рядок основних для полів форми (без зайвих нулів). */
    public static function major(int $minor): string
    {
        $whole = intdiv($minor, self::MINOR_PER_MAJOR);
        $fraction = $minor % self::MINOR_PER_MAJOR;

        return 0 === $fraction ? (string) $whole : rtrim(\sprintf('%d.%02d', $whole, $fraction), '0');
    }
}
