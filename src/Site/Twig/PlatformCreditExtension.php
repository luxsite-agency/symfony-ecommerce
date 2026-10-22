<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Twig;

use App\Core\Settings\PlatformCredit;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * site_platform_credit(): full|short|none — режим рядка платформи у футері (налаштування site.platform_credit).
 * Рядок рендерить site/partials/_platform_credit.html.twig; теми паків include-ять його, а не копіюють розмітку.
 */
final class PlatformCreditExtension extends AbstractExtension
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_platform_credit', fn (): string => PlatformCredit::fromSetting($this->settings->get(SettingKeys::PLATFORM_CREDIT))->value),
        ];
    }
}
