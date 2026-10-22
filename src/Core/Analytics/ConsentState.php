<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics;

/**
 * Consent Mode v2: чотири прапорці Google + чи показувати банер. granted за замовчуванням для мов зі списку
 * налаштування, denied для решти (EU); вибір користувача — cookie shop_consent (granted|denied) на 180 днів.
 */
final readonly class ConsentState
{
    public const string COOKIE = 'shop_consent';

    public function __construct(
        public bool $granted,
        public bool $needsBanner,
        public string $source,
    ) {
    }

    /** @return array<string, string> значення для gtag('consent', 'default', …) */
    public function toGtagDefault(): array
    {
        $value = $this->granted ? 'granted' : 'denied';

        return [
            'ad_storage' => $value,
            'ad_user_data' => $value,
            'ad_personalization' => $value,
            'analytics_storage' => $value,
            'functionality_storage' => 'granted',
            'security_storage' => 'granted',
        ];
    }
}
