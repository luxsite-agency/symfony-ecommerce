<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Catalog;

/**
 * Номери сторінок для пагінації (SEO: кожна сторінка — звичайне посилання з ?page=N): перша, остання і вікно навколо
 * поточної; null — розрив «…». 1 2 3 [4] 5 6 … 40.
 */
final class PageWindow
{
    /** @return list<int|null> */
    public static function pages(int $current, int $count, int $radius = 2): array
    {
        if ($count <= 1) {
            return [];
        }
        $current = max(1, min($current, $count));
        $shown = array_unique([1, ...range(max(1, $current - $radius), min($count, $current + $radius)), $count]);
        sort($shown);
        $pages = [];
        $previous = 0;
        foreach ($shown as $page) {
            if ($page - $previous > 1) {
                $pages[] = $page - $previous === 2 ? $page - 1 : null; // розрив на одну сторінку — показати її саму
            }
            $pages[] = $page;
            $previous = $page;
        }

        return $pages;
    }
}
