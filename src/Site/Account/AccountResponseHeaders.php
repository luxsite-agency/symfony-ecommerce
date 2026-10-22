<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account;

use App\Core\Customer\Http\AccountPath;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Сторінки кабінету й входу (маршрути site_account*, а також будь-яка відповідь під /account/…, зокрема 404 з обрізаним
 * токеном): no-store — назад у браузері на спільному комп'ютері не покаже кабінет після виходу (bfcache); noindex —
 * навіть на відкритому для індексу домені.
 * Referrer-Policy: strict-origin (повторне рев'ю безпеки #45, п. A): Referer будь-якого переходу чи запиту з цих сторінок
 * — лише https://сайт/, тож токен з адреси листа не потрапить ні в document.referrer наступної сторінки (GA4 dr, Pixel rl,
 * «Код у head»), ні в журнал 404, ні стороннім ресурсам. Свої POST при цьому мають справжній Origin, а Referer = origin
 * сайту, тож stateless CSRF (SameOriginCsrfTokenManager: Sec-Fetch-Site, потім Origin/Referer) проходить і в Safari до
 * 16.4, який з no-referrer слав Origin: null (рев'ю #45, п. 6). same-origin не годиться: він віддає повний шлях з токеном
 * своєму сайту. Аналітики й сторонніх кодів на цих сторінках нема зовсім (AnalyticsTags).
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class AccountResponseHeaders
{
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest()
            || (!str_starts_with((string) $request->attributes->get('_route', ''), 'site_account') && !AccountPath::matches($request->getPathInfo()))) {
            return;
        }
        $headers = $event->getResponse()->headers;
        $headers->set('Cache-Control', 'no-store, private');
        // сесія на цих сторінках тепер читається й без входу (кошик покупця через CustomerContext): SessionListener не
        // дописує свої max-age/must-revalidate до заданого no-store
        $headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, '1');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        $headers->set('Referrer-Policy', 'strict-origin');
    }
}
