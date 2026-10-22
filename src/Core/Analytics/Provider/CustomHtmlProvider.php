<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics\Provider;

use App\Core\Analytics\AnalyticsTagProviderInterface;
use App\Core\Analytics\TagContext;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;

/**
 * Три вільні HTML-поля Settings (лише ROLE_ADMIN): виводяться сирими, без екранування, як збережено.
 * Це свідомо довірений канал — редагувати може лише адміністратор.
 */
final class CustomHtmlProvider implements AnalyticsTagProviderInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public static function getPriority(): int
    {
        return 50;
    }

    public function head(TagContext $context): string
    {
        return (string) $this->settings->get(SettingKeys::HTML_HEAD);
    }

    public function bodyStart(TagContext $context): string
    {
        return (string) $this->settings->get(SettingKeys::HTML_BODY_START);
    }

    public function bodyEnd(TagContext $context): string
    {
        return (string) $this->settings->get(SettingKeys::HTML_BODY_END);
    }
}
