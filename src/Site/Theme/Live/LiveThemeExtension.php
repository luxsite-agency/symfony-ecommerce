<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Theme\Live;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** site_theme_live_css() — URL живого theme.css з версією (base.html.twig підключає після стилів рушія й паку) або null. */
final class LiveThemeExtension extends AbstractExtension
{
    public function __construct(private readonly LiveTheme $theme)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('site_theme_live_css', $this->theme->cssUrl(...))];
    }
}
