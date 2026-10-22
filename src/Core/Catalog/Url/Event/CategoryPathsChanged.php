<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Url\Event;

/**
 * CategoryPaths переписав шляхи категорій (слаг, батько, каскад на нащадків, перегенерація слагів): пари «було → стало»
 * там, де нова адреса є (old = null — адреси не було). Подія — у транзакції запису шляхів. Слухач — авто-301
 * (Site\Redirect\AutoRedirects).
 */
final readonly class CategoryPathsChanged
{
    /** @param list<array{locale: string, old: string|null, new: string}> $changes */
    public function __construct(public array $changes)
    {
    }
}
