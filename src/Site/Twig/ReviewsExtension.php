<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Twig;

use App\Core\Catalog\Review\ReviewSettings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** site_reviews_enabled() — чи увімкнено відгуки паком (посилання «Мої відгуки» в кабінеті). */
final class ReviewsExtension extends AbstractExtension
{
    public function __construct(private readonly ReviewSettings $settings)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('site_reviews_enabled', fn (): bool => $this->settings->enabled)];
    }
}
