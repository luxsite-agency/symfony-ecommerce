<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Dashboard;

/** Варіант з обліком залишку (in_stock), активний, із залишком ≤ порога: 0 — «немає», інакше «мало». unit — SalesUnit варіанта. */
final readonly class StockAlert
{
    public function __construct(public int $variantId, public int $productId, public string $sku, public ?string $productName, public string $stock, public ?string $unit = null)
    {
    }

    public function isOut(): bool
    {
        return 0 >= bccomp($this->stock, '0', 3);
    }
}
