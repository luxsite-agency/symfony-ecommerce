<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Url\Message;

/** Перерахувати шляхи піддерев категорій (зміна слага чи батька в адмінці/імпорті) — фоново: піддерево може бути великим. */
final readonly class RebuildCategoryPaths
{
    /** @param list<int> $categoryIds */
    public function __construct(public array $categoryIds)
    {
    }
}
