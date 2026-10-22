<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Synthetic;

/** Вузол синтетичного дерева категорій: index 1…N (він же id у SQL-режимі), label «1-2-3» — шлях номерами. */
final readonly class SyntheticCategory
{
    public function __construct(
        public int $index,
        public ?int $parent,
        public int $depth,
        public string $label,
        public int $position,
    ) {
    }
}
