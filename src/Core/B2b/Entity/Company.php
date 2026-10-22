<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\Entity;

use App\Core\B2b\TaxId;
use App\Core\Shared\Translation\TranslatableInvalidArgumentException;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Компанія оптового клієнта (E13, консиліум 07.10, П2): окрема сутність, покупці приєднані через company_member
 * (у v1 один власник; кілька користувачів компанії з P2 без зміни схеми). Створюється лише схваленням заявки
 * (WholesaleApplications::approve), реквізити копіюються зі снепшоту заявки. Код (ЄДРПОУ/РНОКПП) унікальний.
 */
#[ORM\Entity]
#[ORM\Table(name: 'company')]
#[ORM\UniqueConstraint(name: 'uniq_company_tax_id', columns: ['tax_id'])]
class Company
{
    public const int NAME_MAX = 255;
    public const int CONTACT_MAX = 120;
    public const int PHONE_MAX = 32;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    /** ЄДРПОУ (8 цифр) або РНОКПП (10 цифр); лише цифри. */
    #[ORM\Column(length: 10)]
    private string $taxId;

    #[ORM\Column]
    private bool $vatPayer = false;

    #[ORM\Column(length: self::CONTACT_MAX)]
    private string $contactName;

    #[ORM\Column(length: self::PHONE_MAX, nullable: true)]
    private ?string $contactPhone = null;

    /** @var Collection<int, CompanyMember> */
    #[ORM\OneToMany(targetEntity: CompanyMember::class, mappedBy: 'company', cascade: ['persist'], orphanRemoval: true)]
    private Collection $members;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $taxId, bool $vatPayer, string $contactName, ?string $contactPhone)
    {
        $this->members = new ArrayCollection();
        $this->taxId = TaxId::normalize($taxId);
        $this->rename($name);
        $this->vatPayer = $vatPayer;
        $this->setContact($contactName, $contactPhone);
        $this->createdAt = $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX) {
            throw new TranslatableInvalidArgumentException('Назва компанії має бути непорожньою і не довшою за '.self::NAME_MAX.' символів', 'error.company.name_length', ['max' => (string) self::NAME_MAX]);
        }
        $this->name = $name;
        $this->touch();
    }

    public function getTaxId(): string
    {
        return $this->taxId;
    }

    public function isVatPayer(): bool
    {
        return $this->vatPayer;
    }

    public function setVatPayer(bool $vatPayer): void
    {
        $this->vatPayer = $vatPayer;
        $this->touch();
    }

    public function getContactName(): string
    {
        return $this->contactName;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function setContact(string $name, ?string $phone): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::CONTACT_MAX) {
            throw new TranslatableInvalidArgumentException('Контактна особа: від 1 до '.self::CONTACT_MAX.' символів', 'error.company.contact_length', ['max' => (string) self::CONTACT_MAX]);
        }
        $phone = null === $phone ? null : trim($phone);
        if ('' === $phone) {
            $phone = null;
        }
        if (null !== $phone && mb_strlen($phone) > self::PHONE_MAX) {
            throw new TranslatableInvalidArgumentException('Телефон задовгий', 'error.company.phone_too_long');
        }
        $this->contactName = $name;
        $this->contactPhone = $phone;
        $this->touch();
    }

    /** @return Collection<int, CompanyMember> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(CompanyMember $member): void
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
        }
    }

    /** Для списку в адмінці: email покупців компанії через кому (у v1 один). */
    public function getMemberEmails(): string
    {
        return implode(', ', $this->members->map(static fn (CompanyMember $m): string => $m->getCustomer()->getEmail())->toArray());
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
