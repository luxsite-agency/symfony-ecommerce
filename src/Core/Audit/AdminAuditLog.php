<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Запис і прибирання журналу змін (admin_audit_entry). Пише DBAL-ом: у postFlush ORM-вставка спричинила б
 * вкладений flush; всередині wrapInTransaction (аплаєр пакетів PR-3) рядки лягають у ту саму транзакцію.
 * Зберігання — app.admin_audit_months (0 = не прибирати), щоночі зі Scheduler і командою admin:audit:prune.
 */
final class AdminAuditLog
{
    public function __construct(
        private readonly Connection $db,
        #[Autowire('%app.admin_audit_months%')] private readonly int $retentionMonths,
        private readonly int $pruneBatch = 10_000,
    ) {
    }

    /**
     * @param list<array{action: AuditAction, entity_type: string, entity_id: int, entity_label: string|null, locale: string|null, field: string|null, before: mixed, after: mixed}> $rows
     */
    public function write(AuditActor $actor, array $rows, ?DateTimeImmutable $at = null): void
    {
        if ([] === $rows) {
            return;
        }
        $at = ($at ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $this->db->insert('admin_audit_entry', [
                'occurred_at' => $at,
                'user_id' => $actor->userId,
                'actor' => null === $actor->actor ? null : mb_substr($actor->actor, 0, 180),
                'token_id' => $actor->tokenId,
                'source' => $actor->source->value,
                'action' => $row['action']->value,
                'entity_type' => $row['entity_type'],
                'entity_id' => $row['entity_id'],
                'entity_label' => $row['entity_label'],
                'locale' => $row['locale'],
                'field' => $row['field'],
                'old_value' => self::json($row['before']),
                'new_value' => self::json($row['after']),
                'changeset_id' => $actor->changesetId,
                'ip' => $actor->ip,
            ]);
        }
    }

    /**
     * Пакетами по pruneBatch рядків (рев'ю C #68, L5): перший запуск на великому журналі не тримає одну транзакцію на мільйони рядків.
     *
     * @return int видалених рядків; 0 = зберігання вимкнене
     */
    public function prune(): int
    {
        if ($this->retentionMonths <= 0) {
            return 0;
        }
        $total = 0;
        do {
            $deleted = (int) $this->db->executeStatement(\sprintf('DELETE FROM admin_audit_entry WHERE occurred_at < NOW() - INTERVAL ? MONTH LIMIT %d', max(1, $this->pruneBatch)), [$this->retentionMonths]);
            $total += $deleted;
        } while ($deleted >= max(1, $this->pruneBatch));

        return $total;
    }

    private static function json(mixed $value): ?string
    {
        return null === $value ? null : (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
