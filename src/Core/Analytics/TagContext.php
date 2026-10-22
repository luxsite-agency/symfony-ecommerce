<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics;

/** Контекст виводу тегів на сторінці: мова, стан згоди (Consent Mode v2), чи ввімкнено теги в цьому оточенні. */
final readonly class TagContext
{
    public function __construct(
        public string $locale,
        public bool $enabled,
        public ConsentState $consent,
    ) {
    }
}
