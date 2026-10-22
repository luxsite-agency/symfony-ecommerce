<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

/** Діапазон ціни в мінорних одиницях валюти магазину: у фільтрі — межі вибору, у фасеті — MIN/MAX. */
final readonly class PriceRange
{
    public function __construct(public ?int $min, public ?int $max)
    {
    }

    public function isEmpty(): bool
    {
        return null === $this->min && null === $this->max;
    }
}
