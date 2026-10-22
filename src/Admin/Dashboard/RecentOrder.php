<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Dashboard;

use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\OrderStatus;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;

/** Рядок списку «останні замовлення» / «нові швидкі заявки» — лише поля для таблиці, без гідрації Order. */
final readonly class RecentOrder
{
    public function __construct(
        public int $id,
        public string $number,
        public OrderStatus $status,
        public OrderSource $source,
        public string $customerName,
        public string $phone,
        public Money $total,
        public DateTimeImmutable $placedAt,
    ) {
    }
}
