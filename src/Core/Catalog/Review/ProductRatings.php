<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Review;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Агрегат оцінок товару в таблиці catalog_product_rating (рядок на товар зі схваленими відгуками): кількість і сума
 * оцінок усіх схвалених (зірки на картці, у лістингу, в обраному — демо-відгуки з позначкою входять) і окремо
 * справжніх (без демо) для schema.org AggregateRating. Перераховується одним запитом після кожної зміни стану відгуку
 * (ProductReviews) — картки читають готовий рядок, тож кешу карток, який треба було б інвалідувати, нема: ProductCards
 * збирає картки з БД на кожен запит (кешуються лише id хітів CatalogQuery, без оцінок).
 */
final class ProductRatings
{
    public const string TABLE = 'catalog_product_rating';

    public function __construct(private readonly Connection $db)
    {
    }

    /** Перерахувати агрегат товару зі схвалених відгуків; без схвалених — рядок видаляється. */
    public function refresh(int $productId): void
    {
        $this->refreshMany([$productId]);
    }

    /** @param list<int> $productIds */
    public function refreshMany(array $productIds): void
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ([] === $productIds) {
            return;
        }
        // upsert + видалення «осиротілих» — обидва ідемпотентні, тож два одночасні перерахунки одного товару не дають
        // дублікат PK, як давали DELETE + INSERT окремими запитами (рев'ю #75, L1)
        $this->db->executeStatement(self::UPSERT.' AND product_id IN (?) GROUP BY product_id'.self::ON_DUPLICATE, [$productIds], [ArrayParameterType::INTEGER]);
        $this->db->executeStatement(self::DELETE_ORPHANS.' AND r.product_id IN (?)', [$productIds], [ArrayParameterType::INTEGER]);
    }

    /** Повний перерахунок (після демо-скидання, консоль). */
    public function refreshAll(): void
    {
        $this->db->executeStatement(self::UPSERT.' GROUP BY product_id'.self::ON_DUPLICATE);
        $this->db->executeStatement(self::DELETE_ORPHANS);
    }

    private const string UPSERT = 'INSERT INTO '.self::TABLE.' (product_id, review_count, rating_sum, real_count, real_sum, updated_at)
             SELECT product_id, COUNT(*), SUM(rating), SUM(demo = 0), SUM(IF(demo = 0, rating, 0)), NOW()
             FROM catalog_product_review WHERE status = \'approved\'';
    private const string ON_DUPLICATE = ' ON DUPLICATE KEY UPDATE review_count = VALUES(review_count), rating_sum = VALUES(rating_sum),
             real_count = VALUES(real_count), real_sum = VALUES(real_sum), updated_at = VALUES(updated_at)';
    private const string DELETE_ORPHANS = 'DELETE r FROM '.self::TABLE.' r WHERE NOT EXISTS
             (SELECT 1 FROM catalog_product_review p WHERE p.product_id = r.product_id AND p.status = \'approved\')';

    /**
     * Зірки для карток: id товару → {count, average} лише для товарів зі схваленими відгуками.
     *
     * @param list<int> $productIds
     *
     * @return array<int, RatingSummary>
     */
    public function forProducts(array $productIds): array
    {
        if ([] === $productIds) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT product_id, review_count, rating_sum, real_count, real_sum FROM '.self::TABLE.' WHERE product_id IN (?)',
            [$productIds],
            [ArrayParameterType::INTEGER],
        ) as $r) {
            $out[(int) $r['product_id']] = new RatingSummary((int) $r['review_count'], (int) $r['rating_sum'], (int) $r['real_count'], (int) $r['real_sum']);
        }

        return $out;
    }

    public function forProduct(int $productId): ?RatingSummary
    {
        return $this->forProducts([$productId])[$productId] ?? null;
    }
}
