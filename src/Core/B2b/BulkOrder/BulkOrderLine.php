<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\BulkOrder;

/** Розібраний рядок списку «SKU кількість» (E13 PR-7): ще без каталогу; error — invalid_quantity | header (заголовок CSV). */
final readonly class BulkOrderLine
{
    public function __construct(public int $line, public string $sku, public string $quantity, public ?string $error = null)
    {
    }
}
