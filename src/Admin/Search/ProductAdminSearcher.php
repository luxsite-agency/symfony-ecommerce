<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Search;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Search\ProductTextMatch;
use Doctrine\ORM\QueryBuilder;

/**
 * Товари: кожне слово — підрядок назви будь-якою мовою, SKU чи штрихкоду варіанта або назви бренду; неактивні теж. Слова — AND:
 * «bosch 180» — Bosch з 180 у назві. Підрядок, а не FULLTEXT вітрини: менеджер шукає шматок SKU («quote» з RCK-QUOTE),
 * середину слова, стоп-слова InnoDB («the», «for») — FULLTEXT з префіксом їх не бачить (так шукала адмінка до #103).
 * Id збираються окремими запитами й перетинаються тут, у список адмінки йде entity.id IN (…).
 */
final class ProductAdminSearcher implements AdminSearcher
{
    /** Більше збігів для слова не беремо: список адмінки однаково сторінками, а IN (…) має лишатись коротким. */
    public const int LIMIT = 5000;

    public function __construct(private readonly ProductTextMatch $match)
    {
    }

    public function supports(string $entityFqcn): bool
    {
        return is_a($entityFqcn, Product::class, true);
    }

    public function apply(QueryBuilder $qb, array $terms): void
    {
        $ids = null;
        foreach ($terms as $term) {
            $found = array_fill_keys([
                ...$this->match->byNameContaining($term, self::LIMIT),
                ...$this->match->byCodeContaining($term, self::LIMIT),
                ...$this->match->byBrand($term, false, self::LIMIT),
            ], true);
            $ids = null === $ids ? $found : array_intersect_key($ids, $found);
            if ([] === $ids) {
                break;
            }
        }
        if (null === $ids) {
            return;
        }
        if ([] === $ids) {
            $qb->andWhere('1 = 0');

            return;
        }
        $qb->andWhere('entity.id IN (:smart_search_ids)')->setParameter('smart_search_ids', array_keys($ids));
    }

    public function exactId(string $query): ?int
    {
        $code = trim($query);
        if ('' === $code || str_contains($code, ' ')) {
            return null;
        }
        $ids = $this->match->byExactCode($code);

        return 1 === \count($ids) ? $ids[0] : null;
    }

    public function hintKey(): string
    {
        return 'search.hint.product';
    }
}
