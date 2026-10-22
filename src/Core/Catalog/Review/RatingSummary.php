<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Review;

/** Агрегат оцінок товару: усі схвалені (для показу) і справжні без демо (для schema.org). */
final readonly class RatingSummary
{
    public function __construct(
        public int $count,
        public int $sum,
        public int $realCount,
        public int $realSum,
    ) {
    }

    /** Середня з округленням до десятих, 0.0 без відгуків. */
    public function average(): float
    {
        return $this->count > 0 ? round($this->sum / $this->count, 1) : 0.0;
    }

    public function realAverage(): float
    {
        return $this->realCount > 0 ? round($this->realSum / $this->realCount, 1) : 0.0;
    }

    /** Повних зірок для розмітки (округлення до цілого): 4.5 → 5, 4.4 → 4. */
    public function stars(): int
    {
        return (int) round($this->average());
    }
}
