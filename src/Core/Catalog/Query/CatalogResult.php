<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

/** Результат CatalogQuery: сторінка карток, загальна кількість, фасети і курсор наступної сторінки (keyset). */
final readonly class CatalogResult
{
    /**
     * @param list<CatalogHit>      $hits
     * @param list<FacetDefinition> $definitions фасетний блок категорії в порядку показу
     * @param array{int, int}|null  $nextCursor  (ключ сортування, product_id) останньої картки, якщо далі ще є
     */
    public function __construct(
        public array $hits,
        public int $total,
        public int $page,
        public int $perPage,
        public CatalogFacets $facets,
        public array $definitions,
        public ?array $nextCursor = null,
    ) {
    }

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    /** @return list<int> */
    public function productIds(): array
    {
        return array_map(static fn (CatalogHit $hit): int => $hit->productId, $this->hits);
    }
}
