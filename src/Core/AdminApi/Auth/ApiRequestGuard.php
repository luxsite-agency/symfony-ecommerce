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

/**
 * Розмір тіла запиту до /api/admin і /mcp (E15 PR-6): понад app.admin_api_max_body_bytes → 413 ще до firewall (пріоритет 9 >
 * 8 у firewall: без пошуку токена в БД і без json_decode мегабайтів). nginx ріже те саме на 1m (client_max_body_size у
 * location API), тут — другий рубіж для тіл без Content-Length (chunked) і для стеків без нашого nginx.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
final class ApiRequestGuard
{
    public function __construct(#[Autowire('%app.admin_api_max_body_bytes%')] private readonly int $maxBodyBytes)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !ApiPaths::matches($request)) {
            return;
        }
        $declared = (int) $request->headers->get('Content-Length', '0');
        if ($declared <= $this->maxBodyBytes && \strlen($request->getContent()) <= $this->maxBodyBytes) {
            return;
        }
        $event->setResponse(new JsonResponse(
            ['error' => 'payload_too_large', 'error_description' => \sprintf('Тіло запиту понад %d байт', $this->maxBodyBytes), 'limit' => $this->maxBodyBytes],
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        ));
    }
}
