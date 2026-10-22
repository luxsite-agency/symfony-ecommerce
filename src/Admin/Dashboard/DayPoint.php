<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Dashboard;

use App\Core\Shared\Money\Money;

/** Точка графіка продажів: мітка (день, понеділок тижня або місяць YYYY-MM), кількість, сума і висота стовпчика у відсотках від верхньої позначки шкали (DashboardSnapshot::$salesTicks). */
final readonly class DayPoint
{
    public function __construct(public string $date, public int $count, public Money $total, public int $percent)
    {
    }
}
