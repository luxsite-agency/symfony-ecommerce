<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Auth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Ліміт запитів на токен (limiter admin_api_token, 120/хв): після firewall (пріоритет 8), лише для запитів з API-токеном.
 * Бізнес-ліміт цінових операцій на добу — PR-4/6 (з аудиту).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class ApiRateLimitListener
{
    public function __construct(
        private readonly ApiTokenContext $context,
        #[Autowire(service: 'limiter.admin_api_token')] private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // TokenStorage чіпаємо лише на шляхах firewall admin_api: на інших (у т.ч. stateless-маршрутах сайту — sitemap,
        // robots) звернення до токена позначило б сесію як використану
        if (!$event->isMainRequest() || !ApiPaths::matches($request)) {
            return;
        }
        $token = $this->context->current();
        if (null === $token) {
            return;
        }
        $limit = $this->limiter->create((string) $token->getId())->consume();
        if (!$limit->isAccepted()) {
            $event->setResponse(new JsonResponse(['error' => 'too_many_requests', 'error_description' => 'Забагато запитів, спробуйте пізніше'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]));
        }
    }
}
