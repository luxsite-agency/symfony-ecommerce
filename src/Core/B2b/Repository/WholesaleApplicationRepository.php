<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\Repository;

use App\Core\B2b\Entity\WholesaleApplication;
use App\Core\B2b\Enum\ApplicationStatus;
use App\Core\Customer\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WholesaleApplication> */
final class WholesaleApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WholesaleApplication::class);
    }

    public function findPending(Customer $customer): ?WholesaleApplication
    {
        return $this->findOneBy(['customer' => $customer, 'status' => ApplicationStatus::New]);
    }

    /** Остання заявка покупця (для сторінки кабінету: «чекає», «відхилено: причина», «схвалено»). */
    public function findLatest(Customer $customer): ?WholesaleApplication
    {
        return $this->findOneBy(['customer' => $customer], ['id' => 'DESC']);
    }

    public function countPending(): int
    {
        return $this->count(['status' => ApplicationStatus::New]);
    }
}
