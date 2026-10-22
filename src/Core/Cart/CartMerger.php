<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Cart;

use App\Core\Cart\Entity\Cart;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Customer\Entity\Customer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Symfony\Component\Lock\LockFactory;

/**
 * Злиття гостьового кошика з кошиком покупця при вході (E12 PR-3, консиліум 07.10 П4). Під блокуванням на покупця
 * (два паралельні входи не створять два кошики: unique customer_id) і в транзакції:
 * — cookie-кошик з іншим власником чи прострочений ігнорується (чужий токен нічого не дає, прострочений — як відсутній);
 * — прострочений кошик покупця спершу порожніє (як у CartResolver::current());
 * — кошика покупця нема → гостьовий стає його кошиком;
 * — є → рядки переносяться поштучно через addItem (неактивний товар, інша валюта, зламана кількість — рядок
 *   пропускається і рахується), гостьовий кошик видаляється.
 * Токен кошика покупця ротується тут же ($newTokenHash; cookie ставить CartResolver::adopt()): старий гостьовий токен
 * більше не відкриває кошик.
 * Гонка з паралельним «У кошик» (без блокування; П6): unique customer_id → EM скидається, кошик покупця перечитується
 * і злиття повторюється один раз.
 */
final class CartMerger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
        private readonly LockFactory $locks,
    ) {
    }

    /**
     * @return array{cart: ?Cart, skipped: int} кошик покупця після входу (null — кошика нема ні в гостя, ні в покупця)
     */
    public function mergeOnLogin(Customer $customer, ?Cart $guest, string $newTokenHash): array
    {
        if (null !== $guest && ((null !== $guest->getCustomer() && $guest->getCustomer()->getId() !== $customer->getId()) || $guest->isExpired())) {
            $guest = null;
        }
        $customerId = (int) $customer->getId();
        $guestId = $guest?->getId();
        $lock = $this->locks->createLock('cart-merge-'.$customerId, 30);
        $lock->acquire(true);
        try {
            try {
                return $this->merge($customerId, $guestId, $newTokenHash);
            } catch (UniqueConstraintViolationException) {
                $this->registry->resetManager();

                return $this->merge($customerId, $guestId, $newTokenHash);
            }
        } finally {
            $lock->release();
        }
    }

    /** @return array{cart: ?Cart, skipped: int} */
    private function merge(int $customerId, ?int $guestId, string $newTokenHash): array
    {
        return $this->em->wrapInTransaction(function () use ($customerId, $guestId, $newTokenHash): array {
            $customer = $this->em->find(Customer::class, $customerId) ?? throw new InvalidArgumentException(\sprintf('Покупця #%d не знайдено', $customerId));
            $guest = null === $guestId ? null : $this->em->find(Cart::class, $guestId);
            $own = $this->em->getRepository(Cart::class)->findOneBy(['customer' => $customerId]);
            if (null !== $own && $own->isExpired()) {
                $own->clear();
                $own->touchExpiry();
            }
            if (null === $guest || $guest === $own) {
                $own?->rotateToken($newTokenHash);
                $this->em->flush();

                return ['cart' => $own, 'skipped' => 0];
            }
            if (null === $own) {
                $guest->assignTo($customer);
                $guest->rotateToken($newTokenHash);
                $this->em->flush();

                return ['cart' => $guest, 'skipped' => 0];
            }
            $skipped = 0;
            foreach ($guest->getItems() as $item) {
                try {
                    $own->addItem($item->getVariant(), $item->getQuantity(), $item->getOptionsSnapshot());
                } catch (CatalogInvariantViolation|InvalidArgumentException) {
                    ++$skipped;
                }
            }
            $own->touchExpiry();
            $own->rotateToken($newTokenHash);
            $this->em->remove($guest);
            $this->em->flush();

            return ['cart' => $own, 'skipped' => $skipped];
        });
    }
}
