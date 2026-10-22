<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\Entity;

use App\Core\B2b\Enum\CompanyMemberRole;
use App\Core\Customer\Entity\Customer;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Звʼязок покупця з компанією (консиліум 07.10, П2: таблиця одразу, а не customer.company_id, щоб P2 «кілька
 * користувачів компанії» не міняв схему). У v1 один покупець належить щонайбільше одній компанії (uniq по customer).
 */
#[ORM\Entity]
#[ORM\Table(name: 'company_member')]
#[ORM\UniqueConstraint(name: 'uniq_company_member_customer', columns: ['customer_id'])]
#[ORM\UniqueConstraint(name: 'uniq_company_member_pair', columns: ['company_id', 'customer_id'])]
class CompanyMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(length: 16, enumType: CompanyMemberRole::class)]
    private CompanyMemberRole $role;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(Company $company, Customer $customer, CompanyMemberRole $role = CompanyMemberRole::Owner)
    {
        $this->company = $company;
        $this->customer = $customer;
        $this->role = $role;
        $this->createdAt = new DateTimeImmutable();
        $company->addMember($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getRole(): CompanyMemberRole
    {
        return $this->role;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
