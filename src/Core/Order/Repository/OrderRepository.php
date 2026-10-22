<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Repository;

use App\Core\Customer\Entity\Customer;
use App\Core\Order\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Замовлення покупця (E12 PR-2): кожен запит кабінету фільтрує WHERE customer = :me, «прихованих id» нема (консиліум П5).
 *
 * @extends ServiceEntityRepository<Order>
 */
final class OrderRepository extends ServiceEntityRepository
{
    public const int PAGE_SIZE = 20;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * Сторінка історії покупця: нові зверху, рядки одним запитом (без N+1 на позиціях у списку).
     *
     * @return list<Order>
     */
    public function findForCustomer(Customer $customer, int $page = 1, int $pageSize = self::PAGE_SIZE): array
    {
        /** @var list<Order> $orders */
        $orders = $this->createQueryBuilder('o')
            ->andWhere('o.customer = :customer')->setParameter('customer', $customer)
            ->orderBy('o.placedAt', 'DESC')->addOrderBy('o.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $pageSize)->setMaxResults($pageSize)
            ->getQuery()->getResult();

        return $orders;
    }

    public function countForCustomer(Customer $customer): int
    {
        return $this->count(['customer' => $customer]);
    }

    /** Замовлення покупця за id; чуже чи неіснуюче — null (контролер віддає 404, не 403: без підказки, що id існує). */
    public function findOneForCustomer(int $id, Customer $customer): ?Order
    {
        return $this->findOneBy(['id' => $id, 'customer' => $customer]);
    }

    /**
     * Гостьові замовлення на цей email (точний збіг нормалізованої адреси), ще нічиї.
     *
     * @return list<Order>
     */
    public function findClaimable(string $email): array
    {
        /** @var list<Order> $orders */
        $orders = $this->createQueryBuilder('o')
            ->andWhere('o.email = :email')->andWhere('o.customer IS NULL')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->orderBy('o.id', 'ASC')
            ->getQuery()->getResult();

        return $orders;
    }

    public function countClaimable(string $email): int
    {
        return $this->count(['email' => mb_strtolower(trim($email)), 'customer' => null]);
    }
}
