<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Catalog;

use App\Core\Catalog\Query\CatalogCacheVersion;
use App\Core\Catalog\Query\FacetDefinition;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Підписи фасетного блоку мовою сторінки: назви характеристик, значення (id ↔ код ↔ підпис у порядку position),
 * бренди (id ↔ slug ↔ назва). Без перекладу — код (адмінка вимагає лише код). Характеристики й значення кешуються
 * у versioned namespace CatalogCacheVersion (listener міняє версію при зміні характеристик/значень/брендів).
 */
final class CatalogLabels
{
    private const int TTL = 3600;

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogCacheVersion $version,
        #[Autowire(service: 'cache.app')] private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @param list<FacetDefinition> $definitions
     *
     * @return array<int, array{label: string, options: list<array{id: int, code: string, label: string}>}> attribute_id → підписи
     */
    public function attributes(array $definitions, string $locale): array
    {
        $ids = array_map(static fn (FacetDefinition $d): int => $d->attributeId, $definitions);
        if ([] === $ids) {
            return [];
        }
        sort($ids);
        $key = \sprintf('catalog.labels.%s.%s.%s', $this->version->current(), $locale, hash('xxh128', implode(',', $ids)));

        return $this->cache->get($key, function (ItemInterface $item) use ($ids, $locale): array {
            $item->expiresAfter(self::TTL);

            return $this->loadAttributes($ids, $locale);
        });
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{label: string, options: list<array{id: int, code: string, label: string}>}>
     */
    public function loadAttributes(array $ids, string $locale): array
    {
        $labels = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT a.id, a.code, t.name FROM catalog_attribute a
             LEFT JOIN catalog_attribute_translation t ON t.attribute_id = a.id AND t.locale = ?
             WHERE a.id IN (?)',
            [$locale, $ids],
            [ParameterType::STRING, ArrayParameterType::INTEGER],
        );
        foreach ($rows as $r) {
            $labels[(int) $r['id']] = ['label' => (string) ($r['name'] ?? $r['code']), 'options' => []];
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT v.id, v.attribute_id, v.code, t.value FROM catalog_attribute_value v
             LEFT JOIN catalog_attribute_value_translation t ON t.attribute_value_id = v.id AND t.locale = ?
             WHERE v.attribute_id IN (?)
             ORDER BY v.attribute_id, v.position, v.id',
            [$locale, $ids],
            [ParameterType::STRING, ArrayParameterType::INTEGER],
        );
        foreach ($rows as $r) {
            $attributeId = (int) $r['attribute_id'];
            if (isset($labels[$attributeId])) {
                $labels[$attributeId]['options'][] = ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'label' => (string) ($r['value'] ?? $r['code'])];
            }
        }

        return $labels;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{slug: string, name: string}> у порядку назви
     */
    public function brandsByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $brands = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, slug, name FROM catalog_brand WHERE id IN (?) ORDER BY name, id', [$ids], [ArrayParameterType::INTEGER]) as $r) {
            $brands[(int) $r['id']] = ['slug' => (string) $r['slug'], 'name' => (string) $r['name']];
        }

        return $brands;
    }

    /**
     * @param list<string> $slugs
     *
     * @return array<string, int> slug → id (невідомі відсутні)
     */
    public function brandIdsBySlugs(array $slugs): array
    {
        if ([] === $slugs) {
            return [];
        }
        $ids = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, slug FROM catalog_brand WHERE slug IN (?)', [$slugs], [ArrayParameterType::STRING]) as $r) {
            $ids[(string) $r['slug']] = (int) $r['id'];
        }

        return $ids;
    }
}
