<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Ліміти оформлення (ADR, рев'ю PR #6 п. 7): з IP (app.order_rate_limit_ip) і на телефон (app.order_rate_limit_phone)
 * за app.order_rate_limit_interval. Спільний для checkout і швидкого замовлення.
 */
final class OrderSubmitGuard
{
    public function __construct(
        #[Autowire(service: 'limiter.order_submit')] private readonly RateLimiterFactoryInterface $ipLimiter,
        #[Autowire(service: 'limiter.order_submit_phone')] private readonly RateLimiterFactoryInterface $phoneLimiter,
    ) {
    }

    /** @throws TooManyRequestsHttpException */
    public function check(Request $request, string $rawPhone): void
    {
        if (!$this->ipLimiter->create($request->getClientIp() ?? 'anon')->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(60, 'Забагато спроб оформлення з вашої адреси, спробуйте пізніше');
        }
        // ключ — нормалізований номер, щоб «067…», «+380 67…» і «38067…» рахувались як один
        try {
            $phoneKey = PhoneNormalizer::normalize($rawPhone);
        } catch (InvalidArgumentException) {
            $phoneKey = preg_replace('/\D+/', '', $rawPhone) ?: 'none';
        }
        if ('none' !== $phoneKey && !$this->phoneLimiter->create($phoneKey)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(60, 'Забагато замовлень на цей номер телефону, спробуйте пізніше');
        }
    }
}
