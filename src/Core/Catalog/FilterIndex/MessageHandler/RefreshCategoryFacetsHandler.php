<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\FilterIndex\MessageHandler;

use App\Core\Catalog\FilterIndex\CategorySummaryRefresher;
use App\Core\Catalog\FilterIndex\Message\RefreshCategoryFacets;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RefreshCategoryFacetsHandler
{
    public function __construct(private readonly CategorySummaryRefresher $refresher)
    {
    }

    public function __invoke(RefreshCategoryFacets $message): void
    {
        $this->refresher->refreshQueued();
    }
}
