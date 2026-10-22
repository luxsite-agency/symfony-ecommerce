<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Demo;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Демо-акаунти не можна зіпсувати: зміна пароля й email, видалення акаунта, експорт ПД і скидання пароля на демо-адресу
 * відповідають сторінкою «у демо недоступно» (403). Перевірка за іменами маршрутів кабінету (E12 PR-6), а не за класами
 * контролерів: маршрутів може ще не бути в main, гілка з ними підхопить захист без правок тут.
 * Пріоритет нижчий за firewall (8), щоб користувач уже був у токені. Маршрути з токеном з листів закриті з боку Core:
 * AuthTokens::issue для демо-покупця токен не зберігає, тож жодне посилання для них не діє (рев'ю #66 M3).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final class DemoAccountGuard
{
    /** @var list<string> маршрути, закриті для демо-покупця повністю */
    public const array BLOCKED_ROUTES = ['site_account_profile_password', 'site_account_profile_email', 'site_account_email_confirm', 'site_account_delete', 'site_account_export', 'site_account_export_download', 'site_account_google_link', 'site_account_google_unlink'];
    /** @var list<string> публічні маршрути, де демо-адресу в формі не приймаємо */
    public const array BLOCKED_EMAIL_ROUTES = ['site_account_password', 'site_account_login_link', 'site_account_register'];

    public function __construct(private readonly DemoAccounts $accounts, private readonly Security $security, private readonly Environment $twig)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->accounts->isEnabled()) {
            return;
        }
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        $blocked = \in_array($route, self::BLOCKED_ROUTES, true) && $this->accounts->isDemo($this->security->getUser());
        if (!$blocked && $request->isMethod('POST') && \in_array($route, self::BLOCKED_EMAIL_ROUTES, true)) {
            $posted = $request->request->all();
            $email = $posted['email_request']['email'] ?? $posted['registration']['email'] ?? $request->request->get('email');
            $blocked = \is_string($email) && $this->accounts->isDemoEmail($email);
        }
        if ($blocked) {
            $event->setResponse(new Response($this->twig->render('site/account/demo_blocked.html.twig'), Response::HTTP_FORBIDDEN));
        }
    }
}
