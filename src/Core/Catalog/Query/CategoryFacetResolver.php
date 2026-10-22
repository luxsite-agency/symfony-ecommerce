<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

use App\Core\Catalog\Enum\AttributeType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Фасетний блок категорії (ADR Rev 9, донор params_for_filter): власний список CategoryFilterAttribute → список
 * найближчого предка → усі filterable-характеристики (fallback для каталогу без налаштувань). Лише filterable:
 * характеристика, з якої зняли filterable, зникає з блоку, навіть якщо лишилась у списку категорії.
 * Кешується у versioned namespace CatalogCacheVersion (bump — при зміні категорій/характеристик, див. listener).
 */
final class CategoryFacetResolver
{
    private const int TTL = 3600;

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogCacheVersion $version,
        #[Autowire(service: 'cache.app')] private readonly CacheInterface $cache,
    ) {
    }

    /** @return list<FacetDefinition> у порядку показу */
    public function resolve(?int $categoryId): array
    {
        $key = \sprintf('catalog.facets.%s.%d', $this->version->current(), $categoryId ?? 0);

        return $this->cache->get($key, function (ItemInterface $item) use ($categoryId): array {
            $item->expiresAfter(self::TTL);

            return $this->load($categoryId);
        });
    }

    /** @return list<FacetDefinition> без кешу */
    public function load(?int $categoryId): array
    {
        $rows = [];
        if (null !== $categoryId) {
            $rows = $this->db->fetchAllAssociative(
                'WITH RECURSIVE chain AS (
                   SELECT id, parent_id, 0 AS depth FROM catalog_category WHERE id = ?
                   UNION ALL
                   SELECT c.id, c.parent_id, chain.depth + 1 FROM catalog_category c JOIN chain ON c.id = chain.parent_id
                 ),
                 owner AS (
                   SELECT chain.id FROM chain
                   WHERE EXISTS (SELECT 1 FROM catalog_category_filter_attribute x WHERE x.category_id = chain.id)
                   ORDER BY chain.depth LIMIT 1
                 )
                 SELECT a.id, a.code, a.type, a.unit, a.variant_axis
                 FROM owner
                 JOIN catalog_category_filter_attribute cfa ON cfa.category_id = owner.id
                 JOIN catalog_attribute a ON a.id = cfa.attribute_id AND a.filterable = 1
                 ORDER BY cfa.position, cfa.id',
                [$categoryId],
            );
            if ([] === $rows && $this->hasOwnList($categoryId)) {
                return []; // список є, але всі його характеристики вже не filterable — блок порожній, не fallback
            }
        }
        if ([] === $rows) {
            $rows = $this->db->fetchAllAssociative('SELECT a.id, a.code, a.type, a.unit, a.variant_axis FROM catalog_attribute a WHERE a.filterable = 1 ORDER BY a.position, a.id');
        }

        return array_map(static fn (array $r): FacetDefinition => new FacetDefinition(
            (int) $r['id'],
            (string) $r['code'],
            AttributeType::from((string) $r['type']),
            (bool) $r['variant_axis'],
            null !== $r['unit'] ? (string) $r['unit'] : null,
        ), $rows);
    }

    /** Чи має категорія або хтось із предків власний список (незалежно від filterable). */
    private function hasOwnList(int $categoryId): bool
    {
        return false !== $this->db->fetchOne(
            'WITH RECURSIVE chain AS (
               SELECT id, parent_id FROM catalog_category WHERE id = ?
               UNION ALL
               SELECT c.id, c.parent_id FROM catalog_category c JOIN chain ON c.id = chain.parent_id
             )
             SELECT 1 FROM chain JOIN catalog_category_filter_attribute x ON x.category_id = chain.id LIMIT 1',
            [$categoryId],
        );
    }
}
