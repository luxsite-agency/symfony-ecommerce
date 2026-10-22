<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Entity;

use App\Core\Audit\AuditSource;
use App\Core\Changeset\ChangesetStatus;
use App\Core\Changeset\Dto\Operation;
use App\Core\Security\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use LogicException;

/**
 * Пакет змін (E15 PR-3, консиліум 11.10.2026 §3): запропоновані AI-агентом чи менеджером операції над каталогом і контентом,
 * з diff «було → стане», попередженнями й ризиковими прапорцями з dry-run. Застосовує лише людина в адмінці (рішення власника
 * 11.10, п. 1); ризикові цінові пакети — той самий менеджер з явним підтвердженням (п. 4), факт підтвердження — тут і в журналі.
 * id — int, як changeset_id у admin_audit_entry (#68). Користувачі — SET NULL + знімок імені: пакет переживає видалення
 * користувача. Залишки, замовлення й покупці в операціях не бувають за побудовою (OperationParser, білий список полів).
 */
#[ORM\Entity]
#[ORM\Table(name: 'admin_changeset')]
#[ORM\Index(name: 'idx_admin_changeset_status', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_admin_changeset_user', columns: ['user_id', 'created_at'])]
class Changeset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: ChangesetStatus::class)]
    private ChangesetStatus $status = ChangesetStatus::Proposed;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $user = null;

    /** Ім'я автора на момент створення */
    #[ORM\Column(length: 180)]
    private string $author;

    /** Токен API (PR-2), без FK: токен відкликають і видаляють, пакет лишається */
    #[ORM\Column(nullable: true)]
    private ?int $tokenId = null;

    #[ORM\Column(length: 16, enumType: AuditSource::class)]
    private AuditSource $source;

    #[ORM\Column(length: 255)]
    private string $title;

    /** @var list<array<string, mixed>> нормалізовані операції (після розбору, з розв'язаними id) */
    #[ORM\Column(type: Types::JSON)]
    private array $operations;

    /** @var list<array<string, mixed>> diff по операціях: {index, op, target:{type,id,label}, changes:[{field, locale, before, after}]} */
    #[ORM\Column(type: Types::JSON)]
    private array $diff;

    /** @var list<array<string, mixed>> попередження dry-run: {index, code, message, details} */
    #[ORM\Column(type: Types::JSON)]
    private array $warnings;

    /** @var list<string> zero_price | price_jump | cumulative | mass_price */
    #[ORM\Column(type: Types::JSON)]
    private array $riskFlags;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $appliedBy = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $appliedByName = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $appliedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $rejectedBy = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $rejectedByName = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $rejectedAt = null;

    /** Хто й коли явно підтвердив ризикові прапорці при застосуванні */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $riskConfirmedBy = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $riskConfirmedByName = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $riskConfirmedAt = null;

    /** Пакет, який цей відкочує (інверсні операції з його diff) */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Changeset $revertOf = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    /** Після цього часу proposed-пакет застосувати не можна (app.ai_changeset_ttl) */
    #[ORM\Column]
    private DateTimeImmutable $expiresAt;

    /**
     * @param list<array<string, mixed>> $operations
     * @param list<array<string, mixed>> $diff
     * @param list<array<string, mixed>> $warnings
     * @param list<string>               $riskFlags
     */
    public function __construct(
        ?User $user,
        string $author,
        ?int $tokenId,
        AuditSource $source,
        string $title,
        array $operations,
        array $diff,
        array $warnings,
        array $riskFlags,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $now = null,
    ) {
        $this->user = $user;
        $this->author = mb_substr(trim($author), 0, 180);
        $this->tokenId = $tokenId;
        $this->source = $source;
        $this->title = mb_substr(trim($title), 0, 255);
        $this->operations = $operations;
        $this->diff = $diff;
        $this->warnings = $warnings;
        $this->riskFlags = array_values(array_unique($riskFlags));
        $this->createdAt = $now ?? new DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): ChangesetStatus
    {
        return $this->status;
    }

    /** Стан з урахуванням строку: proposed після expires_at читається як expired, навіть якщо рядок ще не оновлено. */
    public function effectiveStatus(?DateTimeImmutable $now = null): ChangesetStatus
    {
        return $this->isExpired($now) ? ChangesetStatus::Expired : $this->status;
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        return ChangesetStatus::Proposed === $this->status && $this->expiresAt <= ($now ?? new DateTimeImmutable());
    }

    public function isProposed(?DateTimeImmutable $now = null): bool
    {
        return ChangesetStatus::Proposed === $this->status && !$this->isExpired($now);
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function isOwnedBy(User $user): bool
    {
        return null !== $this->user && null !== $user->getId() && $this->user->getId() === $user->getId();
    }

    public function getAuthor(): string
    {
        return $this->author;
    }

    public function getTokenId(): ?int
    {
        return $this->tokenId;
    }

    public function getSource(): AuditSource
    {
        return $this->source;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /** @return list<array<string, mixed>> */
    public function getOperations(): array
    {
        return $this->operations;
    }

    /** @return list<array<string, mixed>> */
    public function getDiff(): array
    {
        return $this->diff;
    }

    /** @return list<array<string, mixed>> */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /** @return list<string> */
    public function getRiskFlags(): array
    {
        return $this->riskFlags;
    }

    public function hasRisk(): bool
    {
        return [] !== $this->riskFlags;
    }

    /** Чи є цінові операції (лист відповідальним за ціни — PR-4) */
    public function hasPriceOperations(): bool
    {
        foreach ($this->operations as $operation) {
            if (\in_array($operation['op'] ?? null, Operation::PRICE_TYPES, true)) {
                return true;
            }
        }

        return false;
    }

    public function getAppliedBy(): ?User
    {
        return $this->appliedBy;
    }

    public function getAppliedByName(): ?string
    {
        return $this->appliedByName;
    }

    public function getAppliedAt(): ?DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function getRejectedByName(): ?string
    {
        return $this->rejectedByName;
    }

    public function getRejectedAt(): ?DateTimeImmutable
    {
        return $this->rejectedAt;
    }

    public function getRiskConfirmedByName(): ?string
    {
        return $this->riskConfirmedByName;
    }

    public function getRiskConfirmedAt(): ?DateTimeImmutable
    {
        return $this->riskConfirmedAt;
    }

    public function getRevertOf(): ?self
    {
        return $this->revertOf;
    }

    public function setRevertOf(?self $revertOf): void
    {
        $this->revertOf = $revertOf;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** @internal лише ChangesetApplier: перевірки стану, підтвердження й транзакція — там */
    public function markApplied(User $by, bool $riskConfirmed, DateTimeImmutable $at): void
    {
        $this->assertProposed($at);
        if ($this->hasRisk()) {
            if (!$riskConfirmed) {
                throw new LogicException('Ризиковий пакет без підтвердження');
            }
            $this->riskConfirmedBy = $by;
            $this->riskConfirmedByName = $by->getDisplayName();
            $this->riskConfirmedAt = $at;
        }
        $this->status = ChangesetStatus::Applied;
        $this->appliedBy = $by;
        $this->appliedByName = $by->getDisplayName();
        $this->appliedAt = $at;
    }

    /** @internal лише ChangesetApplier::reject */
    public function markRejected(User $by, DateTimeImmutable $at): void
    {
        $this->assertProposed($at);
        $this->status = ChangesetStatus::Rejected;
        $this->rejectedBy = $by;
        $this->rejectedByName = $by->getDisplayName();
        $this->rejectedAt = $at;
    }

    /** @internal прострочений proposed → expired (ліниво, при спробі застосувати чи при показі) */
    public function markExpired(): void
    {
        if (ChangesetStatus::Proposed === $this->status) {
            $this->status = ChangesetStatus::Expired;
        }
    }

    private function assertProposed(DateTimeImmutable $at): void
    {
        if (!$this->isProposed($at)) {
            throw new LogicException(\sprintf('Пакет #%d у стані %s', $this->id ?? 0, $this->effectiveStatus($at)->value));
        }
    }

    public function __toString(): string
    {
        return \sprintf('#%d %s', $this->id ?? 0, $this->title);
    }
}
