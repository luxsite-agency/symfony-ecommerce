<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Заглушки Twig-функцій віджета gbp-manager, коли необов'язковий пакет luxsite/gmb-reviews-bundle не встановлено
 * (docs/addons.md): шаблони рушія й тем викликають gmb_* однаково, без пакета — порожньо, блоки оцінки не показуються.
 * Реєструє Kernel::configureContainer лише без пакета (у services.yaml виключено), з пакетом функції дає розширення бандла.
 */
final class GmbReviewsFallbackExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        $empty = static fn (mixed ...$args): string => '';
        $null = static fn (mixed ...$args): mixed => null;

        return [
            new TwigFunction('gmb_reviews', $empty, ['is_safe' => ['html']]),
            new TwigFunction('gmb_reviews_jsonld', $empty, ['is_safe' => ['html']]),
            new TwigFunction('gmb_reviews_data', $null),
            new TwigFunction('gmb_rating', $null),
            new TwigFunction('gmb_rating_badge', $empty, ['is_safe' => ['html']]),
            new TwigFunction('gmb_aggregate_rating', $null),
        ];
    }
}
