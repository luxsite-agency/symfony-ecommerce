<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics\Provider;

use App\Core\Analytics\AnalyticsTagProviderInterface;
use App\Core\Analytics\TagContext;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;

/** Google Tag Manager — офіційний сніпет: script у head, noscript одразу після <body>. Без lazy-load. */
final class GtmProvider implements AnalyticsTagProviderInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public static function getPriority(): int
    {
        return 90;
    }

    public function head(TagContext $context): string
    {
        $id = self::id($this->settings->get(SettingKeys::GTM_CONTAINER_ID));
        if (null === $id) {
            return '';
        }

        return <<<HTML
            <!-- Google Tag Manager -->
            <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
            new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
            j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
            'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
            })(window,document,'script','dataLayer','{$id}');</script>
            <!-- End Google Tag Manager -->
            HTML;
    }

    public function bodyStart(TagContext $context): string
    {
        $id = self::id($this->settings->get(SettingKeys::GTM_CONTAINER_ID));
        if (null === $id) {
            return '';
        }

        return <<<HTML
            <!-- Google Tag Manager (noscript) -->
            <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={$id}"
            height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
            <!-- End Google Tag Manager (noscript) -->
            HTML;
    }

    public function bodyEnd(TagContext $context): string
    {
        return '';
    }

    /** Лише GTM-XXXX (формат у SettingKeys): захист від вставки коду замість id. */
    public static function id(?string $raw): ?string
    {
        return SettingKeys::validValue(SettingKeys::GTM_CONTAINER_ID, $raw);
    }
}
