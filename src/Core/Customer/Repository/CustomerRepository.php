<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Repository;

use App\Core\Customer\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * Provider покупців (security.yaml: customers → entity без property): вхід за email у будь-якому регістрі й з пробілами.
 *
 * @extends ServiceEntityRepository<Customer>
 */
final class CustomerRepository extends ServiceEntityRepository implements UserLoaderInterface, PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    public function findOneByEmail(string $email): ?Customer
    {
        return $this->findOneBy(['email' => Customer::normalizeEmail($email)]);
    }

    public function loadUserByIdentifier(string $identifier): ?Customer
    {
        return $this->findOneByEmail($identifier);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Customer) {
            throw new UnsupportedUserException($user::class);
        }
        $user->upgradePasswordHash($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}
