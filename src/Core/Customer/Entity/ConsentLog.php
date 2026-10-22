<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Entity;

use App\Core\Customer\Enum\ConsentPurpose;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Журнал згод, append-only (консиліум 07.10, П2, П5): кожна подія — надано чи відкликано, версія й хеш тексту політики,
 * джерело (register|profile|delete), час, IP як HMAC (не адреса). Поточний стан — остання подія за призначенням.
 * Рядки не редагуються і не видаляються; при видаленні акаунта лишаються за id покупця.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_consent')]
#[ORM\Index(name: 'idx_customer_consent_customer', columns: ['customer_id', 'purpose', 'id'])]
class ConsentLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(length: 32, enumType: ConsentPurpose::class)]
    private ConsentPurpose $purpose;

    #[ORM\Column]
    private bool $granted;

    #[ORM\Column(length: 32)]
    private string $policyVersion;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $policyHash;

    #[ORM\Column(length: 16)]
    private string $source;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $ipHmac;

    #[ORM\Column]
    private DateTimeImmutable $occurredAt;

    public function __construct(Customer $customer, ConsentPurpose $purpose, bool $granted, string $policyVersion, string $policyHash, string $source, ?string $ipHmac)
    {
        $this->customer = $customer;
        $this->purpose = $purpose;
        $this->granted = $granted;
        $this->policyVersion = mb_substr($policyVersion, 0, 32);
        $this->policyHash = $policyHash;
        $this->source = mb_substr($source, 0, 16);
        $this->ipHmac = $ipHmac;
        $this->occurredAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getPurpose(): ConsentPurpose
    {
        return $this->purpose;
    }

    public function isGranted(): bool
    {
        return $this->granted;
    }

    public function getPolicyVersion(): string
    {
        return $this->policyVersion;
    }

    public function getPolicyHash(): string
    {
        return $this->policyHash;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
