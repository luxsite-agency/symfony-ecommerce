<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics\Provider;

use App\Core\Analytics\AnalyticsTagProviderInterface;
use App\Core\Analytics\TagContext;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;

/** Коди верифікації Google Search Console і Bing як meta. */
final class VerificationMetaProvider implements AnalyticsTagProviderInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public static function getPriority(): int
    {
        return 60;
    }

    public function head(TagContext $context): string
    {
        $tags = [];
        foreach ([SettingKeys::GOOGLE_SITE_VERIFICATION => 'google-site-verification', SettingKeys::BING_SITE_VERIFICATION => 'msvalidate.01'] as $key => $name) {
            $value = SettingKeys::validValue($key, $this->settings->get($key));
            if (null !== $value) {
                $tags[] = \sprintf('<meta name="%s" content="%s">', $name, htmlspecialchars($value, \ENT_QUOTES));
            }
        }

        return implode("\n", $tags);
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
