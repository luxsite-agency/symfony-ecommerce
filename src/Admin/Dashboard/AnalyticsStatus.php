<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Dashboard;

/** Стан аналітики: які теги задані в Settings і чи виводяться зараз (prod завжди, dev — за перемикачем). */
final readonly class AnalyticsStatus
{
    public function __construct(public bool $gtm, public bool $ga4, public bool $pixel, public bool $enabled)
    {
    }

    public function anyConfigured(): bool
    {
        return $this->gtm || $this->ga4 || $this->pixel;
    }
}
