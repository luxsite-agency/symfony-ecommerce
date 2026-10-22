<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Security\Entity;

use App\Core\Security\Enum\UserRole;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Користувач адмінки (ADR D10): одна роль, ROLE_ADMIN ⊃ ROLE_MANAGER через role_hierarchy.
 * Покупці магазину — не тут (акаунтів у v1 нема, D4). Хеш пароля ставить сервіс через setPasswordHash().
 */
#[ORM\Entity]
#[ORM\Table(name: 'security_user')]
#[ORM\UniqueConstraint(name: 'uniq_security_user_email', columns: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'security.user.email.unique')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'security.user.email.not_blank')]
    #[Assert\Email(message: 'security.user.email.email')]
    #[Assert\Length(max: 180)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column(length: 32, enumType: UserRole::class)]
    private UserRole $role;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'security.user.name.not_blank')]
    #[Assert\Length(max: 120)]
    private string $displayName;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    /** Мова інтерфейсу адмінки, яку користувач обрав у меню (AdminLocaleListener); null — типова (AdminLocales::default()). */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $adminLocale = null;

    public function __construct(string $email, string $displayName, UserRole $role = UserRole::Manager)
    {
        $this->email = self::normalizeEmail($email);
        $this->displayName = trim($displayName);
        $this->role = $role;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = self::normalizeEmail($email);
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = trim($displayName);
    }

    public function getAdminLocale(): ?string
    {
        return $this->adminLocale;
    }

    public function setAdminLocale(?string $adminLocale): void
    {
        $this->adminLocale = $adminLocale;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): void
    {
        $this->role = $role;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [$this->role->value];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /** Лише готовий хеш (UserPasswordHasherInterface::hashPassword), не сирий пароль. */
    public function setPasswordHash(string $hash): void
    {
        if ('' === $hash) {
            throw new InvalidArgumentException('Порожній хеш пароля');
        }
        $this->password = $hash;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function eraseCredentials(): void
    {
    }

    public function __toString(): string
    {
        return $this->displayName;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
