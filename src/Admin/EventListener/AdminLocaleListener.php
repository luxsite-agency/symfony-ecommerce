<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\EventListener;

use App\Admin\I18n\AdminLocales;
use App\Core\Security\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Мова інтерфейсу адмінки (AdminLocales: UA / EN — не мови контенту магазину). Лише на admin_host, для кожного запиту:
 * ?_locale=xx (перемикач «UA / EN» у верхній панелі й на вході) → сесія → cookie браузера → типова (мова магазину за
 * замовчуванням, інакше en). Тож вхід en-pharmacy (app.default_locale: en) англійською без параметрів. Пріоритет 17 —
 * після сесії (128) і роутера (32), перед LocaleListener (16), DisplayLocaleListener (15) і перекладачем.
 * Вибір запамʼятовується: у cookie (до входу і після виходу) і в профілі (User::adminLocale) — при зміні мови і при вході
 * (обрана до входу мова переходить у профіль, інакше мова з профілю — у сесію й cookie). REST/MCP (stateless) — без сесії й cookie.
 */
final class AdminLocaleListener
{
    public const SESSION_KEY = '_admin_locale';
    public const COOKIE = 'admin_locale';
    private const REMEMBER = '_admin_locale_remember';

    public function __construct(
        #[Autowire('%admin_host%')] private readonly string $adminHost,
        private readonly AdminLocales $locales,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 17)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->isAdmin($request)) {
            return;
        }
        $requested = $request->query->get('_locale');
        if ($this->locales->supports($requested)) {
            /** @var string $requested */
            $request->setLocale($requested);
            $request->attributes->set(self::REMEMBER, $requested);
            // сторінка входу ще без сесії: вибір має дожити до POST входу (сесію вона однаково відкриває для CSRF)
            if ($request->hasPreviousSession() || 'admin_login' === $request->attributes->get('_route')) {
                $request->getSession()->set(self::SESSION_KEY, $requested);
            }

            return;
        }
        $stored = $request->hasPreviousSession() ? $request->getSession()->get(self::SESSION_KEY) : null;
        $stored = $this->locales->supports($stored) ? $stored : $request->cookies->get(self::COOKIE);
        $request->setLocale($this->locales->supports($stored) ? (string) $stored : $this->locales->default());
    }

    /** Вибір мови → cookie на рік: сторінка входу після виходу й новий вхід на цьому пристрої — тією ж мовою. */
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $locale = $request->attributes->get(self::REMEMBER);
        if (!$event->isMainRequest() || !$this->locales->supports($locale) || $request->cookies->get(self::COOKIE) === $locale) {
            return;
        }
        $event->getResponse()->headers->setCookie(
            Cookie::create(self::COOKIE, (string) $locale, new DateTimeImmutable('+1 year'), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX),
        );
    }

    /** Зміна мови в меню користувача → профіль (після firewall, тож користувач уже відомий). */
    #[AsEventListener(event: KernelEvents::CONTROLLER)]
    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->isAdmin($request) || !$this->locales->supports($request->query->get('_locale'))) {
            return;
        }
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $this->remember($user, $request->getLocale());
        }
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLogin(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        $user = $event->getUser();
        if (!$user instanceof User || !$this->isAdmin($request) || !$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        $chosen = $session->get(self::SESSION_KEY);
        if ($this->locales->supports($chosen)) {
            // обрано перемикачем на сторінці входу — у профіль
            $this->remember($user, (string) $chosen);
        } elseif ($this->locales->supports($user->getAdminLocale())) {
            // профіль важливіший за cookie чужого/старого вибору на цьому пристрої
            $session->set(self::SESSION_KEY, $user->getAdminLocale());
            $request->attributes->set(self::REMEMBER, $user->getAdminLocale());
        } elseif ($this->locales->supports($cookie = $request->cookies->get(self::COOKIE))) {
            $session->set(self::SESSION_KEY, $cookie);
            $this->remember($user, (string) $cookie);
        }
    }

    private function remember(User $user, string $locale): void
    {
        if ($user->getAdminLocale() === $locale) {
            return;
        }
        $user->setAdminLocale($locale);
        $this->em->flush();
    }

    private function isAdmin(Request $request): bool
    {
        return 0 === strcasecmp($request->getHost(), $this->adminHost) && !preg_match('#^/(api/admin|mcp)(/|$)#', $request->getPathInfo());
    }
}
