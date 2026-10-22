<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\BulkOrder;

use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Pricing\LineQuote;
use App\Core\Shared\Money\Money;

/**
 * Рядок списку після звірки з каталогом (E13 PR-7). status: ok | not_found | unavailable | below_min | invalid_step |
 * invalid_quantity | needs_options (товар з обовʼязковими опціями замовляється з картки) | header (заголовок CSV, пропущено). Ціна — за групою покупця.
 */
final readonly class BulkOrderRow
{
    public const string OK = 'ok';

    public function __construct(
        public int $line,
        public string $sku,
        public string $quantity,
        public string $status,
        public ?ProductVariant $variant = null,
        public ?string $name = null,
        public ?LineQuote $quote = null,
    ) {
    }

    public function unitPrice(): ?Money
    {
        return $this->quote?->unitPrice();
    }

    public function lineTotal(): ?Money
    {
        return $this->quote?->unitPrice()->multiplyByDecimal($this->quantity);
    }
}
