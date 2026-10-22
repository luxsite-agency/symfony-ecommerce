<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Crm\Webhook;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Токен вебхука CRM після ротації (crm:webhook:rotate-token): у БД лише sha256, сам токен показується один раз у виводі
 * команди. expires_at = NULL — чинний; після наступної ротації попередній діє ще 24 год (перехідний період, поки
 * в KeyCRM не змінили URL). Поки ротації не було, чинний токен — env CRM_WEBHOOK_TOKEN.
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_webhook_token')]
#[ORM\UniqueConstraint(name: 'uniq_crm_webhook_token_hash', columns: ['token_hash'])]
class CrmWebhookToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $tokenHash;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;

    public function __construct(string $tokenHash, ?DateTimeImmutable $expiresAt = null)
    {
        $this->tokenHash = $tokenHash;
        $this->createdAt = new DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isCurrent(): bool
    {
        return null === $this->expiresAt;
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        return null === $this->expiresAt || $this->expiresAt > $now;
    }

    public function retire(DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt ??= $expiresAt;
    }
}
