<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Studio;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Доступ до /api/studio/* (маршрути api_studio_*): ліміт запитів з IP, потім Bearer-токен STUDIO_API_TOKEN
 * (env, .env.local; порожній або не заданий — приймач вимкнений, 503). Порівняння hash_equals.
 * Слухач kernel.request після RouterListener, тож відмова настає до розбору тіла (MapRequestPayload).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
final class StudioApiGuard
{
    public const string ROUTE_PREFIX = 'api_studio_';

    public function __construct(
        #[Autowire(service: 'limiter.studio_api')] private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire('%env(default::STUDIO_API_TOKEN)%')] private readonly ?string $token = null,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with((string) $request->attributes->get('_route', ''), self::ROUTE_PREFIX)) {
            return;
        }
        $limit = $this->limiter->create($request->getClientIp() ?? 'anon')->consume();
        if (!$limit->isAccepted()) {
            $event->setResponse(new JsonResponse(['error' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
            ]));

            return;
        }
        $token = trim((string) $this->token);
        if ('' === $token) {
            $event->setResponse(new JsonResponse(['error' => 'Receiver not configured (STUDIO_API_TOKEN empty).'], Response::HTTP_SERVICE_UNAVAILABLE));

            return;
        }
        $header = (string) $request->headers->get('Authorization', '');
        $given = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : '';
        if ('' === $given || !hash_equals($token, $given)) {
            $event->setResponse(new JsonResponse(['error' => 'Invalid token.'], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']));
        }
    }
}
