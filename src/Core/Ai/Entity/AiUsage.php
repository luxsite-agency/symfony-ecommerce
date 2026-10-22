<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Ai\Entity;

use App\Core\Ai\AiFeature;
use App\Core\Ai\AiUsageStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Журнал використання AI (E15 BYOK): хто, що, якою моделлю, скільки токенів і кредитів. Текстів запиту й відповіді тут
 * нема (вони стають пакетом змін або показуються у flash) — лише код помилки без тіла. Місячний бюджет рахується сумою cost.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_usage')]
#[ORM\Index(name: 'idx_ai_usage_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_ai_usage_user', columns: ['user_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_ai_usage_entity', columns: ['entity_type', 'entity_id'])]
class AiUsage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column(nullable: true)]
    private ?int $userId;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $actor;

    #[ORM\Column(length: 16, enumType: AiFeature::class)]
    private AiFeature $feature;

    #[ORM\Column(length: 16, enumType: AiUsageStatus::class)]
    private AiUsageStatus $status = AiUsageStatus::Queued;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $entityType;

    #[ORM\Column(nullable: true)]
    private ?int $entityId;

    #[ORM\Column(nullable: true)]
    private ?int $changesetId = null;

    #[ORM\Column]
    private int $promptTokens = 0;

    #[ORM\Column]
    private int $completionTokens = 0;

    /** Кредити OpenRouter (≈ USD), як рядок DECIMAL — без float-похибки в сумах */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, nullable: true)]
    private ?string $cost = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    /** Клас/код помилки без тіла відповіді */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $error = null;

    public function __construct(AiFeature $feature, ?int $userId, ?string $actor, ?string $entityType = null, ?int $entityId = null)
    {
        $this->occurredAt = new DateTimeImmutable();
        $this->feature = $feature;
        $this->userId = $userId;
        $this->actor = null === $actor ? null : mb_strimwidth($actor, 0, 180, '');
        $this->entityType = $entityType;
        $this->entityId = $entityId;
    }

    public function ok(string $model, int $promptTokens, int $completionTokens, ?float $cost, int $durationMs): void
    {
        $this->status = AiUsageStatus::Ok;
        $this->model = mb_strimwidth($model, 0, 128, '');
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;
        $this->cost = null === $cost ? null : number_format($cost, 6, '.', '');
        $this->durationMs = $durationMs;
        $this->error = null;
    }

    public function failed(AiUsageStatus $status, string $error, ?string $model = null, ?int $durationMs = null): void
    {
        $this->status = $status;
        $this->error = mb_strimwidth($error, 0, 255, '…');
        $this->model = null === $model ? $this->model : mb_strimwidth($model, 0, 128, '');
        $this->durationMs = $durationMs;
    }

    public function setChangesetId(?int $changesetId): void
    {
        $this->changesetId = $changesetId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getActor(): ?string
    {
        return $this->actor;
    }

    public function getFeature(): AiFeature
    {
        return $this->feature;
    }

    public function getStatus(): AiUsageStatus
    {
        return $this->status;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function getChangesetId(): ?int
    {
        return $this->changesetId;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function getCost(): ?string
    {
        return $this->cost;
    }

    public function getCostText(): string
    {
        return null === $this->cost ? '—' : '$'.number_format((float) $this->cost, 4, '.', '');
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function __toString(): string
    {
        return $this->feature->label().' #'.($this->id ?? 0);
    }
}
