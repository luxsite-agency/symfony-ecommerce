<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Catalog;

use App\Core\Catalog\FilterIndex\FilterIndexTables;
use App\Core\Catalog\Query\CatalogHit;
use App\Core\Customer\Entity\CustomerGroup;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Картки для заданого списку товарів у заданому порядку (обране, переглянуті): хіти з read-index (лише видимі в
 * каталозі: неактивний чи знятий товар зникає зі списку сам), далі ProductCards і ціни групи покупця поверх.
 */
final readonly class ProductCardsByIds
{
    public function __construct(private Connection $db, private ProductCards $cards, private GroupPriceOverlay $groupPrices)
    {
    }

    /**
     * @param list<int> $productIds
     *
     * @return list<array<string, mixed>>
     */
    public function load(array $productIds, string $locale, ?CustomerGroup $group): array
    {
        if ([] === $productIds) {
            return [];
        }
        $hits = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT v.product_id, MIN(v.price_minor) AS min_price, MAX(v.price_mode) AS price_mode FROM '.FilterIndexTables::VARIANT.' v
             WHERE v.listed = 1 AND v.product_id IN (?) GROUP BY v.product_id',
            [$productIds],
            [ArrayParameterType::INTEGER],
        ) as $r) {
            $hits[(int) $r['product_id']] = new CatalogHit((int) $r['product_id'], (int) $r['min_price'], (int) $r['price_mode']);
        }
        $ordered = [];
        foreach ($productIds as $id) {
            if (isset($hits[$id])) {
                $ordered[] = $hits[$id];
            }
        }
        $cards = array_values(array_filter($this->cards->load($ordered, $locale), static fn (array $c): bool => null !== $c['slug']));

        return $this->groupPrices->apply($cards, $group, $locale);
    }
}
