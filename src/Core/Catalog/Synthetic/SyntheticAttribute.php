<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Synthetic;

use App\Core\Catalog\Enum\AttributeType;

/** Синтетична характеристика: для select/multiselect — кількість значень (коди v01…), для number — діапазон. */
final readonly class SyntheticAttribute
{
    public function __construct(
        public int $index,
        public string $code,
        public AttributeType $type,
        public int $valueCount = 0,
        public bool $axis = false,
        public float $coverage = 1.0,
        public int $numberMin = 0,
        public int $numberMax = 0,
        public int $decimals = 0,
        public ?string $unit = null,
    ) {
    }

    public static function valueCode(int $valueIndex): string
    {
        return \sprintf('v%02d', $valueIndex);
    }
}
