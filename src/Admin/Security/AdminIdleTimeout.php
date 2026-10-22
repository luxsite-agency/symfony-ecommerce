<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Сесії спільні для сайту й адмінки (gc_maxlifetime — доба, для кошика й кабінету). Адмінці — коротший тайм-аут
 * бездіяльності (app.admin_idle_timeout): на admin_host сесію, востаннє використану раніше, ніж тайм-аут тому,
 * анулюємо до firewall (пріоритет 20 > 8; SessionListener — 128), і access_control веде на вхід.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
final class AdminIdleTimeout
{
    public function __construct(
        #[Autowire('%admin_host%')] private readonly string $adminHost,
        #[Autowire('%app.admin_idle_timeout%')] private readonly int $timeout,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // без cookie сесії нема що перевіряти — і не стартуємо нову
        if (!$event->isMainRequest() || $this->timeout <= 0 || !$request->hasPreviousSession() || 0 !== strcasecmp($request->getHost(), $this->adminHost)) {
            return;
        }
        $session = $request->getSession();
        $session->start();
        // lastUsed — час попереднього звернення (MetadataBag оновлює мітку на кожному старті сесії)
        $lastUsed = $session->getMetadataBag()->getLastUsed();
        if ($lastUsed > 0 && time() - $lastUsed > $this->timeout) {
            $session->invalidate();
        }
    }
}
