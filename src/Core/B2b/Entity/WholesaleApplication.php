<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\Entity;

use App\Core\B2b\Enum\ApplicationStatus;
use App\Core\B2b\TaxId;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Security\Entity\User;
use App\Core\Shared\Translation\TranslatableInvalidArgumentException;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use LogicException;

/**
 * Заявка «Стати оптовим клієнтом» (E13.1, консиліум 07.10, П2): реквізити — незмінний снепшот на момент подання,
 * рішення менеджера — хто, коли, коментар, призначена група. Одна активна (new) заявка на покупця: колонка
 * pending_key = 1 лише в стані new, інакше NULL, і унікальний індекс (customer_id, pending_key) тримає це в БД,
 * а не лише в PHP (гонка двох сабмітів). Компанія створюється при схваленні (WholesaleApplications::approve).
 */
#[ORM\Entity]
#[ORM\Table(name: 'wholesale_application')]
#[ORM\UniqueConstraint(name: 'uniq_wholesale_application_pending', columns: ['customer_id', 'pending_key'])]
#[ORM\Index(name: 'idx_wholesale_application_status', columns: ['status', 'created_at'])]
class WholesaleApplication
{
    public const int NOTE_MAX = 1000;
    public const int COMMENT_MAX = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(length: Company::NAME_MAX)]
    private string $companyName;

    #[ORM\Column(length: 10)]
    private string $taxId;

    #[ORM\Column]
    private bool $vatPayer;

    #[ORM\Column(length: Company::CONTACT_MAX)]
    private string $contactName;

    #[ORM\Column(length: Company::PHONE_MAX, nullable: true)]
    private ?string $contactPhone;

    /** Обсяги, асортимент, побажання покупця (вільний текст). */
    #[ORM\Column(length: self::NOTE_MAX, nullable: true)]
    private ?string $note;

    #[ORM\Column(length: 16, enumType: ApplicationStatus::class)]
    private ApplicationStatus $status = ApplicationStatus::New;

    /** 1 у стані new, NULL після рішення (див. unique). */
    #[ORM\Column(nullable: true)]
    private ?int $pendingKey = 1;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    /** Снепшот email рецензента: користувача адмінки можуть видалити, а хто вирішив, має лишитись. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $reviewedByEmail = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $reviewedAt = null;

    /** Коментар менеджера: при відхиленні обовʼязкова причина (йде покупцю в листі), при схваленні за бажанням. */
    #[ORM\Column(length: self::COMMENT_MAX, nullable: true)]
    private ?string $reviewComment = null;

    #[ORM\ManyToOne(targetEntity: CustomerGroup::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CustomerGroup $assignedGroup = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Company $company = null;

    public function __construct(Customer $customer, string $companyName, string $taxId, bool $vatPayer, string $contactName, ?string $contactPhone, ?string $note)
    {
        $companyName = trim($companyName);
        $contactName = trim($contactName);
        if ('' === $companyName || mb_strlen($companyName) > Company::NAME_MAX) {
            throw new TranslatableInvalidArgumentException('Назва компанії має бути непорожньою і не довшою за '.Company::NAME_MAX.' символів', 'error.company.name_length', ['max' => (string) Company::NAME_MAX]);
        }
        if ('' === $contactName || mb_strlen($contactName) > Company::CONTACT_MAX) {
            throw new TranslatableInvalidArgumentException('Контактна особа: від 1 до '.Company::CONTACT_MAX.' символів', 'error.company.contact_length', ['max' => (string) Company::CONTACT_MAX]);
        }
        $contactPhone = null === $contactPhone || '' === trim($contactPhone) ? null : trim($contactPhone);
        if (null !== $contactPhone && mb_strlen($contactPhone) > Company::PHONE_MAX) {
            throw new TranslatableInvalidArgumentException('Телефон задовгий', 'error.company.phone_too_long');
        }
        $note = null === $note || '' === trim($note) ? null : trim($note);
        if (null !== $note && mb_strlen($note) > self::NOTE_MAX) {
            throw new TranslatableInvalidArgumentException('Коментар не довший за '.self::NOTE_MAX.' символів', 'error.wholesale_application.comment_too_long', ['max' => (string) self::NOTE_MAX]);
        }
        $this->customer = $customer;
        $this->companyName = $companyName;
        $this->taxId = TaxId::normalize($taxId);
        $this->vatPayer = $vatPayer;
        $this->contactName = $contactName;
        $this->contactPhone = $contactPhone;
        $this->note = $note;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    public function getTaxId(): string
    {
        return $this->taxId;
    }

    public function isVatPayer(): bool
    {
        return $this->vatPayer;
    }

    public function getContactName(): string
    {
        return $this->contactName;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getStatus(): ApplicationStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return ApplicationStatus::New === $this->status;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function getReviewedByEmail(): ?string
    {
        return $this->reviewedByEmail;
    }

    public function getReviewedAt(): ?DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getReviewComment(): ?string
    {
        return $this->reviewComment;
    }

    public function getAssignedGroup(): ?CustomerGroup
    {
        return $this->assignedGroup;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    /** Рішення «схвалено»: група і компанія обовʼязкові, коментар за бажанням. Лише з new. */
    public function approve(User $reviewer, CustomerGroup $group, Company $company, ?string $comment): void
    {
        $this->decide($reviewer, ApplicationStatus::Approved, $comment);
        $this->assignedGroup = $group;
        $this->company = $company;
    }

    /** Рішення «відхилено»: причина обовʼязкова, вона йде покупцю. Лише з new. */
    public function reject(User $reviewer, string $reason): void
    {
        if ('' === trim($reason)) {
            throw new TranslatableInvalidArgumentException('Вкажіть причину відхилення', 'error.wholesale_application.reason_required');
        }
        $this->decide($reviewer, ApplicationStatus::Rejected, $reason);
    }

    private function decide(User $reviewer, ApplicationStatus $status, ?string $comment): void
    {
        if (!$this->isPending()) {
            throw new LogicException('Заявку вже розглянуто');
        }
        $comment = null === $comment || '' === trim($comment) ? null : trim($comment);
        if (null !== $comment && mb_strlen($comment) > self::COMMENT_MAX) {
            throw new TranslatableInvalidArgumentException('Коментар не довший за '.self::COMMENT_MAX.' символів', 'error.wholesale_application.comment_too_long', ['max' => (string) self::COMMENT_MAX]);
        }
        $this->status = $status;
        $this->pendingKey = null;
        $this->reviewedBy = $reviewer;
        $this->reviewedByEmail = $reviewer->getEmail();
        $this->reviewedAt = new DateTimeImmutable();
        $this->reviewComment = $comment;
    }
}
