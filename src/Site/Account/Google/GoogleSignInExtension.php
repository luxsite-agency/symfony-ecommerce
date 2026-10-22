<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Google;

use App\Core\Customer\Google\GoogleSignInConfig;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `google_sign_in_enabled()` для шаблонів: кнопка «Увійти через Google» (site/account/_google_button.html.twig) показується
 * лише з ключами. Партіал приймає `next` (відносний шлях повернення) — ним користується й модальне «увійдіть, щоб
 * залишити відгук» (reviews-feature.md).
 */
final class GoogleSignInExtension extends AbstractExtension
{
    public function __construct(private readonly GoogleSignInConfig $config)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('google_sign_in_enabled', $this->config->isEnabled(...))];
    }
}
