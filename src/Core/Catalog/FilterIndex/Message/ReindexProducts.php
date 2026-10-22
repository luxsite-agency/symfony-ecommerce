<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\FilterIndex\Message;

/**
 * Регенерувати рядки read-index фільтрів для товарів (ADR Rev 9): після зміни характеристик, осей, категорій,
 * варіантів. Диспатчить FilterIndexDoctrineListener після flush; обробляє ReindexProductsHandler (Messenger async).
 */
final readonly class ReindexProducts
{
    /** @param list<int> $productIds */
    public function __construct(public array $productIds)
    {
    }
}
