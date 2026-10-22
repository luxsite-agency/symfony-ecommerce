<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Synthetic;

use App\Core\Catalog\Enum\StockPolicy;

final readonly class SyntheticVariant
{
    public function __construct(
        public int $priceMinor,
        public ?int $oldPriceMinor,
        public int $stock,
        public StockPolicy $policy,
        /** індекс значення осі (1…), null — картка з одним варіантом */
        public ?int $axisValue,
    ) {
    }
}
