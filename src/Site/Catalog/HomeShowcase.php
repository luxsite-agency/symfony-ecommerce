<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Catalog;

use App\Core\Catalog\FilterIndex\FilterIndexTables;
use App\Core\Catalog\Query\CatalogHit;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Дані головної з read-index (ті самі правила видимості, що в лістингу). Вітрина: спершу товари з прапорцем
 * «Показувати на головній» (featured), доповнення — найновіші; товари без фото — лише в кінці, якщо з фото не вистачило.
 * Плитки категорій — кількість товарів у піддереві і зображення: власне зображення категорії або фото першого
 * (найстарішого) товару з фото. Кілька запитів на сторінку, кожен — обхід індексу з LIMIT.
 */
final readonly class HomeShowcase
{
    private const string HAS_PHOTO = 'EXISTS (SELECT 1 FROM catalog_product_media pm WHERE pm.product_id = v.product_id)';

    public function __construct(private Connection $db)
    {
    }

    /**
     * @return array{hits: list<CatalogHit>, featured: bool} featured — у вітрині є хоч один обраний товар
     */
    public function showcase(int $limit): array
    {
        $limit = max(1, $limit);
        $featured = $this->hits(
            'JOIN catalog_product p ON p.id = v.product_id AND p.featured = 1 WHERE v.listed = 1',
            'v.product_id',
            $limit,
            withPhotoFlag: true,
        );
        $featuredIds = array_map(static fn (array $h): int => $h['hit']->productId, $featured);
        $withPhoto = array_values(array_filter($featured, static fn (array $h): bool => $h['photo']));
        $withoutPhoto = array_values(array_filter($featured, static fn (array $h): bool => !$h['photo']));
        $hits = array_map(static fn (array $h): CatalogHit => $h['hit'], $withPhoto);

        if (\count($hits) < $limit) {
            $hits = [...$hits, ...$this->newest($limit - \count($hits), $featuredIds, photo: true)];
        }
        foreach ($withoutPhoto as $h) {
            if (\count($hits) < $limit) {
                $hits[] = $h['hit'];
            }
        }
        if (\count($hits) < $limit) {
            $hits = [...$hits, ...$this->newest($limit - \count($hits), $featuredIds, photo: false)];
        }
        $shownIds = array_flip(array_map(static fn (CatalogHit $h): int => $h->productId, $hits));

        return [
            'hits' => $hits,
            'featured' => [] !== array_filter($featuredIds, static fn (int $id): bool => isset($shownIds[$id])),
        ];
    }

    /**
     * @param list<int> $categoryIds
     *
     * @return array<int, array{count: int, image: ?string}> категорія => кількість видимих товарів і шлях зображення
     */
    public function categoryTiles(array $categoryIds): array
    {
        if ([] === $categoryIds) {
            return [];
        }
        $tiles = array_fill_keys($categoryIds, ['count' => 0, 'image' => null]);
        foreach ($this->db->fetchAllAssociative(
            'SELECT pc.category_id, COUNT(*) AS cnt, (
                SELECT m.path FROM '.FilterIndexTables::PRODUCT_CATEGORY.' pc2
                JOIN catalog_product_media pm ON pm.product_id = pc2.product_id AND pm.variant_id IS NULL
                JOIN catalog_media m ON m.id = pm.media_id
                WHERE pc2.category_id = pc.category_id AND pc2.listed = 1
                ORDER BY pc2.product_id, pm.position, pm.id LIMIT 1
             ) AS image
             FROM '.FilterIndexTables::PRODUCT_CATEGORY.' pc
             WHERE pc.category_id IN (?) AND pc.listed = 1
             GROUP BY pc.category_id',
            [$categoryIds],
            [ArrayParameterType::INTEGER],
        ) as $r) {
            $tiles[(int) $r['category_id']] = ['count' => (int) $r['cnt'], 'image' => null !== $r['image'] ? (string) $r['image'] : null];
        }
        // власне зображення категорії (адмінка → «Зображення плитки») перекриває фото товару
        foreach ($this->db->fetchAllAssociative(
            'SELECT c.id, m.path FROM catalog_category c JOIN catalog_media m ON m.id = c.image_id WHERE c.id IN (?)',
            [$categoryIds],
            [ArrayParameterType::INTEGER],
        ) as $r) {
            $tiles[(int) $r['id']]['image'] = (string) $r['path'];
        }

        return $tiles;
    }

    /**
     * Найновіші видимі товари (за id, як сортування «нові» в лістингу) з фото або без, крім уже взятих.
     *
     * @param list<int> $excludeIds
     *
     * @return list<CatalogHit>
     */
    private function newest(int $limit, array $excludeIds, bool $photo): array
    {
        $where = 'WHERE v.listed = 1 AND '.($photo ? '' : 'NOT ').self::HAS_PHOTO;
        if ([] !== $excludeIds) {
            $where .= ' AND v.product_id NOT IN ('.implode(',', $excludeIds).')';
        }

        return array_map(static fn (array $h): CatalogHit => $h['hit'], $this->hits($where, 'v.product_id DESC', $limit));
    }

    /**
     * @return list<array{hit: CatalogHit, photo: bool}>
     */
    private function hits(string $joinWhere, string $orderBy, int $limit, bool $withPhotoFlag = false): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT v.product_id, MIN(v.price_minor) AS min_price, MAX(v.price_mode) AS price_mode'
            .($withPhotoFlag ? ', '.self::HAS_PHOTO.' AS photo' : '')
            .' FROM '.FilterIndexTables::VARIANT.' v '.$joinWhere
            .' GROUP BY v.product_id ORDER BY '.$orderBy.' LIMIT '.$limit,
        );

        return array_map(static fn (array $r): array => [
            'hit' => new CatalogHit((int) $r['product_id'], (int) $r['min_price'], (int) $r['price_mode']),
            'photo' => !$withPhotoFlag || (bool) $r['photo'],
        ], $rows);
    }
}
