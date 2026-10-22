<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

use DateTimeImmutable;

/**
 * Зведення категорії без фільтрів (F0, ADR Rev 11 п. 4): total, лічильники всіх filterable-характеристик (осі — на рівні
 * purchasable, решта — listed), бренди, діапазони чисел і ціни, «в наявності». Читається без TTL і глобальної версії
 * кешу; відстає від індексу на затримку фонового перерахунку (CategoryFacetQueue).
 */
final readonly class CategorySummary
{
    public function __construct(
        public int $categoryId,
        public int $total,
        public CatalogFacets $facets,
        public DateTimeImmutable $computedAt,
    ) {
    }
}
