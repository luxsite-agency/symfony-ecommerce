<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Synthetic;

use App\Core\Catalog\Enum\PriceMode;

final readonly class SyntheticProduct
{
    /**
     * @param list<int>                             $categories індекси листових категорій (перша — основна)
     * @param array<int, int|string|bool|list<int>> $attributes індекс характеристики → індекс значення (select),
     *                                                          список індексів (multiselect), рядок-число, bool
     * @param list<SyntheticVariant>                $variants
     */
    public function __construct(
        public int $number,
        public array $categories,
        public ?int $brand,
        public bool $active,
        public PriceMode $priceMode,
        public array $attributes,
        public array $variants,
    ) {
    }
}
