<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\PriceList;

use App\Core\Shared\Money\Money;

/**
 * Рядок прайс-листа групи (E13 PR-8): один варіант на одну кількість «від». Варіант з порогами дає кілька рядків
 * (мінімальна партія і кожен поріг вище), Excel-фільтр за SKU показує всі ціни варіанта.
 */
final readonly class PriceListRow
{
    public function __construct(
        public string $sku,
        public string $name,
        public string $category,
        public string $unit,
        public string $fromQuantity,
        public Money $groupPrice,
        public Money $retailPrice,
        public string $availability,
        public ?string $stock,
        public string $minQuantity,
        public string $quantityStep,
        public ?string $packQuantity,
    ) {
    }
}
