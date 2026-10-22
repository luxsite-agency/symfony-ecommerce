<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Synthetic;

use InvalidArgumentException;

/** Параметри синтетичного каталогу для benchmark (Ф1): однаковий spec + seed → однаковий каталог. */
final readonly class SyntheticCatalogSpec
{
    public const int MAX_VARIANTS = 12;

    public function __construct(
        public int $products = 1000,
        public int $maxVariants = 3,
        public int $categories = 463,
        public int $attributes = 10,
        public int $brands = 300,
        public int $seed = 1,
    ) {
        if ($products < 1 || $categories < 1 || $attributes < 0 || $brands < 1) {
            throw new InvalidArgumentException('products, categories, brands ≥ 1; attributes ≥ 0');
        }
        if ($maxVariants < 1 || $maxVariants > self::MAX_VARIANTS) {
            throw new InvalidArgumentException(\sprintf('variants-per-product: 1…%d', self::MAX_VARIANTS));
        }
    }
}
