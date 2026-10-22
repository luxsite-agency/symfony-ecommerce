<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics\Provider;

use App\Core\Analytics\AnalyticsTagProviderInterface;
use App\Core\Analytics\TagContext;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;

/** GA4 через gtag.js — лише коли GTM не задано (інакше GA4 підключається всередині контейнера). */
final class Ga4Provider implements AnalyticsTagProviderInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public static function getPriority(): int
    {
        return 80;
    }

    public function head(TagContext $context): string
    {
        if (null !== GtmProvider::id($this->settings->get(SettingKeys::GTM_CONTAINER_ID))) {
            return '';
        }
        $id = SettingKeys::validValue(SettingKeys::GA4_MEASUREMENT_ID, $this->settings->get(SettingKeys::GA4_MEASUREMENT_ID));
        if (null === $id) {
            return '';
        }

        return <<<HTML
            <!-- Google tag (gtag.js) -->
            <script async src="https://www.googletagmanager.com/gtag/js?id={$id}"></script>
            <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
            gtag('js',new Date());gtag('config','{$id}');</script>
            HTML;
    }

    public function bodyStart(TagContext $context): string
    {
        return '';
    }

    public function bodyEnd(TagContext $context): string
    {
        return '';
    }
}
