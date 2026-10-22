<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Crm;

use App\Core\Shared\Quantity\Quantity;

/**
 * Рівень залишку з CRM для одного варіанта: доступно = inStock − inReserve, від'ємне обрізається до 0 (ADR, п. 5 PR #1).
 * externalId — ідентифікатор варіанта в CRM (для KeyCRM offer_id), зберігається в ExternalRef.
 */
final readonly class StockLevel
{
    public string $available;
    public bool $clamped;

    public function __construct(
        public string $sku,
        public string $inStock,
        public string $inReserve = '0',
        public ?string $externalId = null,
    ) {
        $available = bcsub(Quantity::normalize($inStock), Quantity::normalize($inReserve), 3);
        $this->clamped = -1 === bccomp($available, '0', 3);
        $this->available = $this->clamped ? '0.000' : $available;
    }
}
