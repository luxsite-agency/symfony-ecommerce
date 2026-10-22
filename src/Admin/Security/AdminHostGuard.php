<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * EasyAdmin ставить host лише на маршрут дашборду; CRUD-маршрути (admin_*) без host матчились і на хості магазину,
 * де firewall site (раніше main) не вимагає входу. Тут: будь-який маршрут адмінки поза admin_host → 404, до firewall (пріоритет 20 > 8).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
final class AdminHostGuard
{
    public function __construct(#[Autowire('%admin_host%')] private readonly string $adminHost)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if (('admin' === $route || str_starts_with($route, 'admin_')) && 0 !== strcasecmp($request->getHost(), $this->adminHost)) {
            throw new NotFoundHttpException('Адмінка доступна лише на хості '.$this->adminHost);
        }
    }
}
