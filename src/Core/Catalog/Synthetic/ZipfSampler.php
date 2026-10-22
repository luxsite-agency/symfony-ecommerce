<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Synthetic;

use Random\Randomizer;

/** Вибірка рангу 0…n-1 з імовірністю ∝ 1/(k+1)^s: кілька великих категорій/брендів/значень і довгий хвіст. */
final class ZipfSampler
{
    /** @var list<float> */
    private array $cumulative = [];
    private float $total = 0.0;

    public function __construct(int $n, float $s = 1.0)
    {
        for ($k = 1; $k <= max(1, $n); ++$k) {
            $this->total += 1.0 / $k ** $s;
            $this->cumulative[] = $this->total;
        }
    }

    public function sample(Randomizer $random): int
    {
        $x = $random->nextFloat() * $this->total;
        $lo = 0;
        $hi = \count($this->cumulative) - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->cumulative[$mid] < $x) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    /** Частка рангу k (0…) — для звіту: скільки товарів очікувано в найбільшій категорії. */
    public function share(int $k): float
    {
        return ($this->cumulative[$k] - ($k > 0 ? $this->cumulative[$k - 1] : 0.0)) / $this->total;
    }
}
