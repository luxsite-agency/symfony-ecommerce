<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Таблиця catalog_filter_category_summary (ADR Rev 11 п. 4): один рядок на категорію, фасети — JSON. Читання — PK
 * lookup без кешу застосунку; запис — повний перерахунок категорії (без дельт).
 */
final class CategorySummaryStore
{
    public const string TABLE = 'catalog_filter_category_summary';

    public function __construct(private readonly Connection $db)
    {
    }

    public function get(int $categoryId): ?CategorySummary
    {
        $row = $this->db->fetchAssociative('SELECT total, facets, computed_at FROM '.self::TABLE.' WHERE category_id = ?', [$categoryId]);
        if (false === $row) {
            return null;
        }
        $data = json_decode((string) $row['facets'], true, 16, \JSON_THROW_ON_ERROR);

        return new CategorySummary($categoryId, (int) $row['total'], CatalogFacets::fromArray(\is_array($data) ? $data : []), new DateTimeImmutable((string) $row['computed_at']));
    }

    public function save(int $categoryId, int $total, CatalogFacets $facets): void
    {
        $this->db->executeStatement(
            'INSERT INTO '.self::TABLE.' (category_id, total, facets, computed_at) VALUES (?, ?, ?, NOW(6))
             ON DUPLICATE KEY UPDATE total = VALUES(total), facets = VALUES(facets), computed_at = VALUES(computed_at)',
            [$categoryId, $total, json_encode($facets->toArray(), \JSON_THROW_ON_ERROR)],
        );
    }

    /** @param list<int> $keep категорії, що існують; решту (видалені категорії) прибрати */
    public function deleteExcept(array $keep): void
    {
        if ([] === $keep) {
            $this->db->executeStatement('DELETE FROM '.self::TABLE);

            return;
        }
        $this->db->executeStatement('DELETE FROM '.self::TABLE.' WHERE category_id NOT IN (?)', [$keep], [ArrayParameterType::INTEGER]);
    }
}
