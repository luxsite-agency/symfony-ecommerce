<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics;

use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use Symfony\Component\HttpFoundation\Request;

/** Стан згоди для запиту: cookie користувача → інакше налаштування «мови зі згодою за замовчуванням». */
final class ConsentPolicy
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function isGrantedByDefault(string $locale): bool
    {
        return \in_array($locale, $this->settings->getList(SettingKeys::CONSENT_GRANTED_LOCALES), true);
    }

    public function resolve(?Request $request, string $locale): ConsentState
    {
        $cookie = $request?->cookies->get(ConsentState::COOKIE);
        if ('granted' === $cookie) {
            return new ConsentState(true, false, 'cookie');
        }
        if ('denied' === $cookie) {
            return new ConsentState(false, false, 'cookie');
        }
        $granted = $this->isGrantedByDefault($locale);

        return new ConsentState($granted, !$granted, 'default');
    }
}
