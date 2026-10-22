<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Dashboard;

use DateTimeImmutable;

/**
 * Стан інтеграцій: активний CRM-конектор і чи вміє він pushOrder, остання прив'язка замовлення / товару в CRM
 * (integration_external_ref), замовлення періоду без прив'язки, черга Messenger (failed і невивезені async).
 */
final readonly class IntegrationStatus
{
    public function __construct(
        public string $crmSystem,
        public bool $crmPushOrders,
        public ?DateTimeImmutable $lastOrderPushAt,
        public ?DateTimeImmutable $lastCatalogSyncAt,
        public int $ordersWithoutCrmRef,
        public int $failedMessages,
        public int $pendingMessages,
        public ?DateTimeImmutable $oldestPendingAt,
    ) {
    }
}
