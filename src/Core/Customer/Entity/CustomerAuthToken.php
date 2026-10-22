<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Entity;

use App\Core\Customer\Enum\AuthTokenPurpose;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Одноразовий токен з листа (підтвердження email, відновлення пароля). У БД — лише sha256 токена; сам токен живе в листі.
 * email — адреса, на яку пішов лист: токен дійсний, лише поки email покупця той самий. Пароль токен не несе: при
 * підтвердженні email його задає власник скриньки на сторінці з листа (рев'ю безпеки #45, п. 2).
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_auth_token')]
#[ORM\UniqueConstraint(name: 'uniq_customer_auth_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_customer_auth_token_expires', columns: ['expires_at'])]
class CustomerAuthToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(length: 32, enumType: AuthTokenPurpose::class)]
    private AuthTokenPurpose $purpose;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $tokenHash;

    #[ORM\Column(length: Customer::EMAIL_MAX)]
    private string $email;

    /** Для ChangeEmail — нова адреса, на яку надіслано лист; для решти null. */
    #[ORM\Column(length: Customer::EMAIL_MAX, nullable: true)]
    private ?string $targetEmail = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $consumedAt = null;

    public function __construct(Customer $customer, AuthTokenPurpose $purpose, string $tokenHash, DateTimeImmutable $expiresAt, ?string $targetEmail = null)
    {
        $this->customer = $customer;
        $this->purpose = $purpose;
        $this->tokenHash = $tokenHash;
        $this->email = $customer->getEmail();
        $this->targetEmail = $targetEmail;
        $this->createdAt = new DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getPurpose(): AuthTokenPurpose
    {
        return $this->purpose;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getTargetEmail(): ?string
    {
        return $this->targetEmail;
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        // RevertEmail живе попри подальші зміни адреси: інакше друга зміна зловмисником гасила б посилання власника
        return null === $this->consumedAt && $now < $this->expiresAt && $this->customer->isActive()
            && (AuthTokenPurpose::RevertEmail === $this->purpose || $this->email === $this->customer->getEmail());
    }

    public function consume(): void
    {
        $this->consumedAt = new DateTimeImmutable();
    }
}
