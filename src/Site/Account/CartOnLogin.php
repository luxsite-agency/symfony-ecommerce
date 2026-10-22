<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account;

use App\Core\Cart\CartMerger;
use App\Core\Cart\CartResolver;
use App\Core\Cart\Entity\Cart;
use App\Core\Customer\Entity\Customer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Http\Authenticator\Debug\TraceableAuthenticator;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Кошик при вході й виході покупця (firewall site, E12 PR-3): гостьовий кошик зливається з кошиком покупця
 * (CartMerger), токен ротується й cookie оновлюється в тій самій відповіді; пропущені рядки — флеш. Remember-me
 * (автовхід на будь-якій сторінці) не зливає нічого: cookie в цьому браузері вже вказує на кошик покупця.
 * Вихід знімає cookie кошика; кошик у БД лишається за покупцем до наступного входу.
 * Будь-який збій злиття (рев'ю #58 п. 2) не валить автентифікацію: лог, cookie кошика знімається, вхід триває —
 * кошик покупця (якщо є) повернеться наступним запитом за customer_id.
 */
final class CartOnLogin
{
    public function __construct(
        private readonly CartResolver $carts,
        private readonly CartMerger $merger,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(priority: -10)]
    public function onLogin(LoginSuccessEvent $event): void
    {
        $customer = $event->getUser();
        if ('site' !== $event->getFirewallName() || !$customer instanceof Customer) {
            return;
        }
        $authenticator = $event->getAuthenticator();
        if ($authenticator instanceof TraceableAuthenticator) {
            $authenticator = $authenticator->getAuthenticator();
        }
        if ($authenticator instanceof RememberMeAuthenticator) {
            return;
        }
        $token = CartResolver::newToken();
        try {
            ['cart' => $cart, 'skipped' => $skipped] = $this->merger->mergeOnLogin($customer, $this->carts->cookieCart(), Cart::hashToken($token));
        } catch (Throwable $e) {
            $this->logger->error('Злиття кошика при вході не вдалося: {message}', ['message' => $e->getMessage(), 'exception' => $e, 'customer' => $customer->getId()]);
            if (!$this->em->isOpen()) {
                $this->registry->resetManager();
            }
            $this->carts->dropCookie();

            return;
        }
        if (null === $cart) {
            $this->carts->dropCookie();

            return;
        }
        $this->carts->adopt($cart, $token);
        $session = $event->getRequest()->hasSession() ? $event->getRequest()->getSession() : null;
        if ($skipped > 0 && $session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $this->translator->trans('site.cart.merge_skipped', ['count' => $skipped]));
        }
    }

    #[AsEventListener]
    public function onLogout(LogoutEvent $event): void
    {
        if ($event->getToken()?->getUser() instanceof Customer) {
            $this->carts->dropCookie();
        }
    }
}
