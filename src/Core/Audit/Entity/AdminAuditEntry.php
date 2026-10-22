<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Audit\Entity;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditedEntities;
use App\Core\Audit\AuditSource;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Журнал змін адмінки (E15 PR-1, консиліум 11.10.2026 п. 1): хто, коли, звідки й що змінив у каталозі, цінах і контенті.
 * Рядок на поле при зміні; один рядок зі знімком при створенні/видаленні. Пише лише AdminAuditLog (DBAL, у postFlush),
 * через ORM не створюється; у адмінці — лише читання. user_id без FK: рядки переживають видалення користувача,
 * ім'я на момент дії — у actor. Персональних даних покупців тут не буває за побудовою: замовлення й покупці не журналюються.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'admin_audit_entry')]
#[ORM\Index(name: 'idx_admin_audit_entity', columns: ['entity_type', 'entity_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_admin_audit_user', columns: ['user_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_admin_audit_changeset', columns: ['changeset_id'])]
#[ORM\Index(name: 'idx_admin_audit_source_entity', columns: ['source', 'entity_type', 'entity_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_admin_audit_occurred', columns: ['occurred_at'])]
class AdminAuditEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column(nullable: true)]
    private ?int $userId = null;

    /** Ім'я користувача на момент дії (або назва системного актора) */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $actor = null;

    #[ORM\Column(nullable: true)]
    private ?int $tokenId = null;

    #[ORM\Column(length: 16, enumType: AuditSource::class)]
    private AuditSource $source;

    #[ORM\Column(length: 16, enumType: AuditAction::class)]
    private AuditAction $action;

    #[ORM\Column(length: 32)]
    private string $entityType;

    #[ORM\Column]
    private int $entityId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $entityLabel = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $field = null;

    /** @var mixed значення до зміни (update), знімок полів (delete); колонка old_value — before/after у MySQL зарезервовані */
    #[ORM\Column(name: 'old_value', type: Types::JSON, nullable: true)]
    private mixed $before = null;

    /** @var mixed значення після зміни (update), знімок полів (create) */
    #[ORM\Column(name: 'new_value', type: Types::JSON, nullable: true)]
    private mixed $after = null;

    #[ORM\Column(nullable: true)]
    private ?int $changesetId = null;

    /** Анонімізований IP (IpUtils::anonymize) */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

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

    public function getTokenId(): ?int
    {
        return $this->tokenId;
    }

    public function getSource(): AuditSource
    {
        return $this->source;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityTypeLabel(): string
    {
        return AuditedEntities::typeLabel($this->entityType);
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function getEntityLabel(): ?string
    {
        return $this->entityLabel;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public function getBefore(): mixed
    {
        return $this->before;
    }

    public function getAfter(): mixed
    {
        return $this->after;
    }

    /** Для полів адмінки (EasyAdmin TextField приймає лише рядки) */
    public function getBeforeText(): string
    {
        return self::format($this->before, true);
    }

    public function getAfterText(): string
    {
        return self::format($this->after, true);
    }

    /**
     * Для списку журналу: «Товар «Двері Лінея» #12». З перекладачем — мовою адмінки (entity_type.*, audit.subject.*),
     * без нього — українською (як __toString()).
     */
    public function getSubjectText(?TranslatorInterface $translator = null): string
    {
        $hasLabel = null !== $this->entityLabel && '' !== $this->entityLabel;
        if (null !== $translator) {
            return $translator->trans($hasLabel ? 'audit.subject.labelled' : 'audit.subject.plain', [
                'type' => AuditedEntities::typeMessage($this->entityType)->trans($translator),
                'label' => (string) $this->entityLabel,
                'id' => (string) $this->entityId,
            ], 'admin');
        }

        return $this->getEntityTypeLabel().($hasLabel ? ' «'.$this->entityLabel.'»' : '').' #'.$this->entityId;
    }

    /**
     * Для списку журналу одним рядком (аудит UX A12): «Назва (uk): Двері → Двері Лінея»; створення й видалення — словом
     * (знімок полів — на сторінці запису), пакет змін — його підсумок. Значення обрізані до $max символів.
     * З перекладачем — назви полів і слова мовою адмінки (audit.field_name.*, audit.summary.*, audit.value.*), без нього — українською.
     */
    public function getChangeSummary(int $max = 80, ?TranslatorInterface $translator = null): string
    {
        $word = static fn (string $key, string $uk): string => null === $translator ? $uk : $translator->trans($key, [], 'admin');
        $yes = $word('audit.value.yes', 'так');
        $no = $word('audit.value.no', 'ні');
        $cut = static fn (string $t): string => mb_strlen($t) > $max ? mb_substr($t, 0, $max).'…' : $t;
        $flat = static fn (mixed $v): string => self::flat($v, $translator, $yes, $no);
        $field = null === $this->field ? '' : AuditedEntities::fieldLabel($this->field, $translator).(null === $this->locale ? '' : ' ('.$this->locale.')').': ';

        return match ($this->action) {
            AuditAction::Create => $field.$word('audit.summary.created', 'створено'),
            AuditAction::Delete => $field.$word('audit.summary.deleted', 'видалено'),
            AuditAction::Update => $field.$cut($flat($this->before)).' → '.$cut($flat($this->after)),
            default => $cut($flat($this->after ?? $this->before)),
        };
    }

    /** Масив — «ключ: значення; …» замість JSON, решта — як format(). */
    private static function flat(mixed $value, ?TranslatorInterface $translator, string $yes, string $no): string
    {
        if (!\is_array($value)) {
            return (string) preg_replace('/\s+/', ' ', self::format($value, false, $yes, $no));
        }
        $parts = [];
        foreach ($value as $k => $v) {
            $parts[] = (\is_int($k) ? '' : AuditedEntities::fieldLabel((string) $k, $translator).': ').self::format($v, false, $yes, $no);
        }

        return [] === $parts ? '—' : implode('; ', $parts);
    }

    public function getChangesetId(): ?int
    {
        return $this->changesetId;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    /** Значення для показу: скаляр як є, інше — JSON; «так/ні» адмінка передає мовою інтерфейсу (AdminAuditCrudController). */
    public static function format(mixed $value, bool $pretty = false, string $yes = 'так', string $no = 'ні'): string
    {
        if (null === $value) {
            return '—';
        }
        if (\is_bool($value)) {
            return $value ? $yes : $no;
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | ($pretty ? \JSON_PRETTY_PRINT : 0));
    }

    /** Український підпис (поза адмінкою); заголовок сторінки запису в адмінці — audit.title.detail */
    public function __toString(): string
    {
        return \sprintf('%s %s #%d', $this->action->label(), $this->getEntityTypeLabel(), $this->entityId);
    }
}
