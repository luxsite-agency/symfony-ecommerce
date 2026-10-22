<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Wishlist;

use App\Core\Catalog\Entity\Product;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\WishlistItem;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/** Обране покупця в БД (E12 PR-10): лише для залогіненого; гість обраного не має (без cookie й сесії). */
final class Wishlist
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<int> id товарів, найновіші першими */
    public function productIds(Customer $customer): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->em->createQuery('SELECT IDENTITY(w.product) AS id FROM '.WishlistItem::class.' w WHERE w.customer = :c ORDER BY w.createdAt DESC, w.id DESC')
            ->setParameter('c', $customer)->getArrayResult();

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /** @return bool true — додано, false — уже було */
    public function add(Customer $customer, Product $product): bool
    {
        if (null !== $this->find($customer, $product)) {
            return false;
        }
        $this->em->persist(new WishlistItem($customer, $product));
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return false; // паралельний клік: товар уже в обраному
        }

        return true;
    }

    /** @return bool true — прибрано, false — не було */
    public function remove(Customer $customer, Product $product): bool
    {
        $item = $this->find($customer, $product);
        if (null === $item) {
            return false;
        }
        $this->em->remove($item);
        $this->em->flush();

        return true;
    }

    private function find(Customer $customer, Product $product): ?WishlistItem
    {
        return $this->em->getRepository(WishlistItem::class)->findOneBy(['customer' => $customer, 'product' => $product]);
    }
}
