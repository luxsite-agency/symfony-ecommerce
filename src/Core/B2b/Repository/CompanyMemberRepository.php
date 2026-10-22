<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\Repository;

use App\Core\B2b\Entity\CompanyMember;
use App\Core\Customer\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CompanyMember> */
final class CompanyMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompanyMember::class);
    }

    /** У v1 покупець належить щонайбільше одній компанії. */
    public function findByCustomer(Customer $customer): ?CompanyMember
    {
        return $this->findOneBy(['customer' => $customer]);
    }
}
