<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Dashboard;

use App\Core\Shared\Money\Money;
use DateTimeImmutable;

/** Зріз усіх віджетів за діапазон дат — те, що кладеться в кеш і рендериться шаблоном. */
final readonly class DashboardSnapshot
{
    /**
     * @param list<DayPoint>      $salesByDay
     * @param list<TopProduct>    $topByTotal
     * @param list<TopProduct>    $topByQuantity
     * @param list<RecentOrder>   $recentOrders
     * @param list<RecentOrder>   $quickRequests
     * @param list<StockAlert>    $stockAlerts
     * @param list<PendingReview> $pendingReviews
     * @param list<Money>         $salesTicks     позначки осі сум графіка від нуля до верхньої (3–4 «круглі» кроки)
     */
    public function __construct(
        public DashboardRange $range,
        public DateTimeImmutable $generatedAt,
        public OrdersSummary $orders,
        public array $salesByDay,
        public array $topByTotal,
        public array $topByQuantity,
        public array $recentOrders,
        public int $quickRequestsCount,
        public array $quickRequests,
        public int $outOfStockCount,
        public int $lowStockCount,
        public array $stockAlerts,
        public IntegrationStatus $integrations,
        public AnalyticsStatus $analytics,
        public int $pendingReviewsCount = 0,
        public array $pendingReviews = [],
        public array $salesTicks = [],
    ) {
    }
}
