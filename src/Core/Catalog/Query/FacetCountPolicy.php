<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;

/**
 * Налаштування магазину про лічильники фільтрів (ADR Rev 11, рішення власника): «показувати лічильники» (за
 * замовчуванням вимкнено) і поріг N карток у відфільтрованому наборі (20000, невалідне значення → 20000).
 */
final class FacetCountPolicy
{
    public const int DEFAULT_MAX_SET = 20000;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function current(): FacetCounting
    {
        $max = trim((string) $this->settings->get(SettingKeys::CATALOG_FACET_COUNTS_MAX_SET));

        return new FacetCounting(
            $this->settings->getBool(SettingKeys::CATALOG_FACET_COUNTS_ENABLED),
            '' !== $max && ctype_digit($max) ? (int) $max : self::DEFAULT_MAX_SET,
        );
    }
}
