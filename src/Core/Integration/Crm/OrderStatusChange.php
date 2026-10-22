<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Crm;

use DateTimeImmutable;

/** Зміна статусу замовлення на боці CRM (вебхук або poll). Обробка — у Ф2 разом з Order. */
final readonly class OrderStatusChange
{
    public function __construct(
        public string $externalOrderId,
        public string $status,
        public DateTimeImmutable $changedAt,
        public ?string $siteOrderNumber = null,
    ) {
    }
}
