<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Repository;

use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerAddress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Адреси покупця: кожен запит WHERE customer = :me (консиліум П5, ніяких прихованих id).
 *
 * @extends ServiceEntityRepository<CustomerAddress>
 */
final class CustomerAddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerAddress::class);
    }

    /** @return list<CustomerAddress> за замовчуванням — першою */
    public function findForCustomer(Customer $customer): array
    {
        /** @var list<CustomerAddress> $rows */
        $rows = $this->findBy(['customer' => $customer], ['isDefault' => 'DESC', 'id' => 'ASC']);

        return $rows;
    }

    public function findOneForCustomer(int $id, Customer $customer): ?CustomerAddress
    {
        return $this->findOneBy(['id' => $id, 'customer' => $customer]);
    }

    public function findDefault(Customer $customer): ?CustomerAddress
    {
        return $this->findOneBy(['customer' => $customer], ['isDefault' => 'DESC', 'id' => 'ASC']);
    }

    /** Зробити адресу основною: прапорець знімається з решти адрес цього покупця (flush робить викликач). */
    public function makeDefault(CustomerAddress $address): void
    {
        foreach ($this->findForCustomer($address->getCustomer()) as $other) {
            $other->setDefault($other === $address);
        }
    }

    public function deleteAllOf(Customer $customer): int
    {
        return (int) $this->createQueryBuilder('a')->delete()->andWhere('a.customer = :c')->setParameter('c', $customer)->getQuery()->execute();
    }
}
