<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Catalog;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Query\CatalogHit;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Сутності товарів сторінки лістингу для ecommerce-подій (EcommerceEventBuilder працює з ProductVariant): два
 * fetch-join запити на сторінку — товари з варіантами, перекладами, брендом і основною категорією; осі варіантів
 * зі значеннями й перекладами (item_variant). Без них analytics_view_item_list/select_item дали б N+1 лінивих.
 */
final readonly class ListingProducts
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param list<CatalogHit> $hits
     *
     * @return array<int, Product> id → товар, у порядку хітів
     */
    public function load(array $hits): array
    {
        $ids = array_map(static fn (CatalogHit $h): int => $h->productId, $hits);
        if ([] === $ids) {
            return [];
        }
        /** @var list<Product> $products */
        $products = $this->em->createQueryBuilder()->select('p', 'v', 't', 'b', 'c', 'ct')->from(Product::class, 'p')
            ->leftJoin('p.variants', 'v')->leftJoin('p.translations', 't')->leftJoin('p.brand', 'b')
            ->leftJoin('p.mainCategory', 'c')->leftJoin('c.translations', 'ct')
            ->where('p.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
        $this->em->createQueryBuilder()->select('v', 'av', 'val', 'vt')->from(ProductVariant::class, 'v')
            ->leftJoin('v.axisValues', 'av')->leftJoin('av.value', 'val')->leftJoin('val.translations', 'vt')
            ->where('v.product IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();

        $byId = [];
        foreach ($products as $product) {
            $byId[(int) $product->getId()] = $product;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id]) && !$byId[$id]->getVariants()->isEmpty()) {
                $ordered[$id] = $byId[$id];
            }
        }

        return $ordered;
    }
}
