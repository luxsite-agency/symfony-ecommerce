<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Dashboard;

use App\Core\Shared\Money\Money;

/** Рядок топу товарів за період (за снепшотом sku/назви в рядках замовлень, без скасованих); unit — SalesUnit варіанта, null, якщо варіант видалено. */
final readonly class TopProduct
{
    public function __construct(public string $sku, public string $name, public string $quantity, public Money $total, public ?string $unit = null)
    {
    }
}
