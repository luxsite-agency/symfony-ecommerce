<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Ліміт некешованих розрахунків фасетів лістингу з одного IP (ADR Rev 11 п. 6, limiter.catalog_listing:
 * app.catalog_listing_rate_limit за app.catalog_listing_rate_interval). Понад ліміт CatalogQuery рахує лише сторінку
 * і total, фасети — без лічильників; відповідь 200, не 429 (покупець бачить каталог, бот не кладе MySQL).
 * Поза HTTP-запитом (CLI, воркер) не обмежує.
 */
final class ListingRateLimit
{
    public function __construct(
        private readonly RequestStack $requests,
        #[Autowire(service: 'limiter.catalog_listing')] private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function consume(): bool
    {
        $request = $this->requests->getMainRequest();
        if (null === $request) {
            return true;
        }

        return $this->limiter->create($request->getClientIp() ?? 'anon')->consume()->isAccepted();
    }
}
