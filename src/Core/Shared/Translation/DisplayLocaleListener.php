<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Translation;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Мова показу сутностей (DisplayLocale) = мова запиту: після LocaleListener (16) і AdminLocaleListener (17), тож в адмінці —
 * мова адмінки, на сайті — мова сторінки. reset() (kernel.reset між запитами воркера, старт консольної команди) — мова за замовчуванням.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 15)]
#[AsEventListener(event: ConsoleEvents::COMMAND, method: 'reset')]
final class DisplayLocaleListener implements ResetInterface
{
    public function __construct(#[Autowire('%app.default_locale%')] private readonly string $defaultLocale)
    {
        DisplayLocale::set(null, $this->defaultLocale);
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            DisplayLocale::set($event->getRequest()->getLocale(), $this->defaultLocale);
        }
    }

    public function reset(): void
    {
        DisplayLocale::set(null, $this->defaultLocale);
    }
}
