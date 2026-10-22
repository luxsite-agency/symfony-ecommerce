<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Crm;

/** Підсумок застосування рівнів залишку: що змінилось, що вже було таким, чого не знайшли, де від'ємне обрізано. */
final class StockApplyReport
{
    public int $changed = 0;
    public int $unchanged = 0;
    public int $clamped = 0;
    /** @var list<string> */
    public array $unknownSkus = [];
    public int $refsLearned = 0;

    public function merge(self $other): void
    {
        $this->changed += $other->changed;
        $this->unchanged += $other->unchanged;
        $this->clamped += $other->clamped;
        $this->refsLearned += $other->refsLearned;
        $this->unknownSkus = [...$this->unknownSkus, ...$other->unknownSkus];
    }

    public function total(): int
    {
        return $this->changed + $this->unchanged + \count($this->unknownSkus);
    }
}
