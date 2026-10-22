<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Cart;

use App\Core\Cart\Entity\Cart;
use App\Core\Cart\Exception\CartCreationRace;
use App\Core\Customer\CurrentCustomer;
use App\Core\Customer\Entity\Customer;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Кошик поточного відвідувача (ADR D4): cookie shop_cart несе bearer-токен, у БД лежить sha256-хеш.
 * Створюється лінива (лише коли справді щось кладуть) і тоді ж ставиться cookie Secure/HttpOnly/SameSite=Lax.
 * Покупець (E12 PR-3, консиліум 07.10 П4): залогінений отримує кошик за customer_id (cookie не має значення: чужий або
 * старий токен не відкриє інший кошик), гість — лише cookie-кошик без власника. Токен ротується при вході (CartMerger),
 * при виході cookie знімається, після замовлення кошик покупця очищається в БД.
 * Стан — на один запит: ResetInterface (kernel.reset) скидає його між запитами в довгоживучому процесі (worker-рантайм,
 * store:smoke кандидата), інакше другий запит бачив би кошик першого.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse')]
final class CartResolver implements ResetInterface
{
    public const string COOKIE = 'shop_cart';

    private ?Cart $resolved = null;
    private bool $lookedUp = false;
    private ?string $pendingToken = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requests,
        private readonly CurrentCustomer $customers,
        #[Autowire('%app.default_currency%')] private readonly string $currency,
        private readonly ManagerRegistry $registry,
    ) {
    }

    /** Кошик відвідувача або null, якщо його ще нема (нічого не створює). */
    public function current(): ?Cart
    {
        if ($this->lookedUp) {
            return $this->resolved;
        }
        $this->lookedUp = true;
        $customerId = $this->customers->customerId();
        if (null !== $customerId) {
            $cart = $this->em->getRepository(Cart::class)->findOneBy(['customer' => $customerId]);
            if (null !== $cart && $cart->isExpired()) {
                // прострочений кошик покупця не можна «забути» (unique customer_id): він порожніє і живе далі
                $cart->clear();
                $cart->touchExpiry();
            }

            return $this->resolved = $cart;
        }
        $cart = $this->cookieCart();
        if (null !== $cart && (null !== $cart->getCustomer() || $cart->isExpired())) {
            $cart = null;
        }

        return $this->resolved = $cart;
    }

    /** Кошик за cookie без огляду на власника й строк (для злиття при вході); повз кеш запиту. */
    public function cookieCart(): ?Cart
    {
        $token = (string) $this->requests->getMainRequest()?->cookies->get(self::COOKIE, '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return $this->em->getRepository(Cart::class)->findOneBy(['tokenHash' => Cart::hashToken($token)]);
    }

    /**
     * Кошик відвідувача, створений за потреби (перший add-to-cart). Кошик залогіненого пишеться в БД одразу: два
     * паралельні запити без кошика впруться в unique customer_id, і програвший отримує CartCreationRace (EM скинуто,
     * повторне читання за customer_id уже бачить кошик переможця), а не 500 із закритим EntityManager.
     *
     * @throws CartCreationRace
     */
    public function currentOrCreate(): Cart
    {
        $cart = $this->current();
        if (null !== $cart) {
            return $cart;
        }
        $token = bin2hex(random_bytes(32));
        $cart = new Cart(Cart::hashToken($token), $this->currency);
        $customerId = $this->customers->customerId();
        $this->em->persist($cart);
        if (null !== $customerId) {
            $cart->assignTo($this->em->getReference(Customer::class, $customerId));
            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException $e) {
                $this->registry->resetManager();
                $this->resolved = null;
                $this->lookedUp = false;
                throw new CartCreationRace($customerId, $e);
            }
        }
        $this->pendingToken = $token;
        $this->resolved = $cart;
        $this->lookedUp = true;

        return $cart;
    }

    /** Новий bearer-токен (для ротації при вході: хеш — у Cart::rotateToken(), сам токен — в adopt()). */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Кошик покупця після входу стає поточним, cookie цієї відповіді отримує $token (його хеш уже в кошику). */
    public function adopt(Cart $cart, string $token): void
    {
        $this->pendingToken = $token;
        $this->resolved = $cart;
        $this->lookedUp = true;
    }

    /**
     * Після оформлення замовлення: cookie знімається; гостьові рядки лишаються за TTL для cleanup, кошик покупця
     * очищається в БД одразу (інакше він повернеться з наступним запитом за customer_id).
     */
    public function forget(): void
    {
        $cart = $this->current();
        if (null !== $cart && null !== $cart->getCustomer()) {
            $cart->clear();
            $this->em->flush();
        }
        $this->resolved = null;
        $this->lookedUp = true;
        $this->pendingToken = '';
    }

    /** Вихід покупця: cookie знімається, кошик у БД лишається за покупцем. */
    public function dropCookie(): void
    {
        $this->resolved = null;
        $this->lookedUp = true;
        $this->pendingToken = '';
    }

    public function reset(): void
    {
        $this->resolved = null;
        $this->lookedUp = false;
        $this->pendingToken = null;
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || null === $this->pendingToken) {
            return;
        }
        $secure = $event->getRequest()->isSecure();
        if ('' === $this->pendingToken) {
            $event->getResponse()->headers->clearCookie(self::COOKIE, '/', null, $secure, true, Cookie::SAMESITE_LAX);
        } else {
            $event->getResponse()->headers->setCookie(Cookie::create(self::COOKIE, $this->pendingToken, new DateTimeImmutable(Cart::TTL), '/', null, $secure, true, false, Cookie::SAMESITE_LAX));
        }
        $this->pendingToken = null;
    }
}
