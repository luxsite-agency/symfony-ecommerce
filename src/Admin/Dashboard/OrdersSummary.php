<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Dashboard;

use App\Core\Shared\Money\Money;

/** Лічильники замовлень: нові (стан new, без періоду), за сьогодні/7/30 днів і сума/середній чек обраного періоду (без скасованих). */
final readonly class OrdersSummary
{
    /** @param array<string, int> $byStatus стан → кількість за період, у порядку OrderStatus */
    public function __construct(
        public int $new,
        public int $today,
        public int $last7Days,
        public int $last30Days,
        public int $periodCount,
        public Money $periodTotal,
        public Money $averageCheck,
        public array $byStatus,
    ) {
    }
}
