<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Review;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Параметри паку app.reviews.* (config/services.yaml, перевизначення — config/parameters.yaml паку). */
final readonly class ReviewSettings
{
    public function __construct(
        #[Autowire('%app.reviews.enabled%')] public bool $enabled,
        #[Autowire('%app.reviews.premoderation%')] public bool $premoderation,
        #[Autowire('%app.reviews.require_google%')] public bool $requireGoogle,
        #[Autowire('%app.reviews.daily_limit%')] public int $dailyLimit,
        #[Autowire('%app.reviews.page_size%')] public int $pageSize,
    ) {
    }
}
