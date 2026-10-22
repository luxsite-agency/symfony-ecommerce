<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Authenticator\LoginLinkAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * CSRF для входу одноразовим посиланням (рев'ю безпеки #45, п. 3). LoginLinkAuthenticator сам CSRF не перевіряє: чужа
 * сторінка автосабмітом POST на посилання зловмисника (його власний лист) впустила б жертву в акаунт зловмисника
 * (login CSRF), і все, що жертва далі введе, опинилося б у чужому кабінеті. Бейдж додається раніше за
 * CsrfProtectionListener (пріоритет 512) і раніше за UserCheckerListener (256), який і витрачає посилання: чужий POST
 * не впускає й посилання не з'їдає. Токен stateless `account` (csrf.yaml): Origin / Sec-Fetch-Site свого сайту.
 */
final class LoginLinkCsrf
{
    public const string TOKEN_ID = 'account';
    public const string FIELD = '_token';

    public function __construct(private readonly RequestStack $requests)
    {
    }

    #[AsEventListener(event: CheckPassportEvent::class, priority: 600, dispatcher: 'security.event_dispatcher.site')]
    public function __invoke(CheckPassportEvent $event): void
    {
        if (!$event->getAuthenticator() instanceof LoginLinkAuthenticator) {
            return;
        }
        $passport = $event->getPassport();
        if ($passport->hasBadge(CsrfTokenBadge::class)) {
            return;
        }
        $token = $this->requests->getCurrentRequest()?->request->get(self::FIELD);
        $passport->addBadge(new CsrfTokenBadge(self::TOKEN_ID, \is_string($token) ? $token : null));
    }
}
