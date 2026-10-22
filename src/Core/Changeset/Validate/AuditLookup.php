<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Validate;

use App\Core\Changeset\Dto\Operation;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Читання admin_audit_entry (#68) для пакетів: базова ціна варіанта N днів тому для правила cumulative (найраніший old_value
 * поля priceMinor серед змін пакетами (changeset_id) у вікні — ціна до першої зміни пакетом, незалежно від джерела: ai_api,
 * cli, ai_assist) і «хто/коли» останньої зміни поля для конфліктів. Вікна рахуються часом PHP (Europe/Kyiv, як occurred_at),
 * не NOW() MySQL (UTC у контейнері).
 */
final class AuditLookup
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** Ціна (мінорні одиниці) до першої AI-зміни варіанта за останні $days днів; null — AI його не чіпав. */
    public function priceBaseline(int $variantId, int $days, ?DateTimeImmutable $now = null): ?int
    {
        $value = $this->db->fetchOne(
            'SELECT old_value FROM admin_audit_entry WHERE changeset_id IS NOT NULL AND entity_type = ? AND entity_id = ? AND field = ? AND occurred_at >= ? ORDER BY occurred_at ASC, id ASC LIMIT 1',
            ['product_variant', $variantId, 'priceMinor', self::since($days.' days', $now)],
        );
        if (false === $value || null === $value) {
            return null;
        }
        $decoded = json_decode((string) $value, true);

        return \is_int($decoded) ? $decoded : null;
    }

    /** Цінових операцій (рядків priceMinor у price_tier і product_variant) з цього токена за поточну добу */
    public function priceOperationsToday(int $tokenId, ?DateTimeImmutable $now = null): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM admin_audit_entry WHERE token_id = ? AND occurred_at >= ? AND ((entity_type = ? AND field = ?) OR entity_type = ?)',
            [$tokenId, self::since('1 day', $now), 'product_variant', 'priceMinor', 'price_tier'],
        );
    }

    /**
     * Цінових операцій у чинних proposed пакетах цього токена (рев'ю C #77 M1): добовий ліміт рахує «застосовано + в черзі»,
     * інакше серія пакетів до першого apply його обходить. Відхилені й прострочені квоту вивільняють самі.
     */
    public function pendingPriceOperations(int $tokenId, ?DateTimeImmutable $now = null): int
    {
        $rows = $this->db->fetchFirstColumn(
            'SELECT operations FROM admin_changeset WHERE token_id = ? AND status = ? AND expires_at > ?',
            [$tokenId, 'proposed', ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
        $n = 0;
        foreach ($rows as $json) {
            $operations = json_decode((string) $json, true);
            foreach (\is_array($operations) ? $operations : [] as $operation) {
                if (\is_array($operation) && \in_array($operation['op'] ?? null, Operation::PRICE_TYPES, true)) {
                    ++$n;
                }
            }
        }

        return $n;
    }

    private static function since(string $interval, ?DateTimeImmutable $now): string
    {
        return ($now ?? new DateTimeImmutable())->modify('-'.$interval)->format('Y-m-d H:i:s');
    }

    /**
     * Остання зміна поля після $since: хто й коли (для повідомлення про конфлікт).
     *
     * @return array{changed_by: string|null, changed_at: string, source: string}|null
     */
    public function lastChange(string $entityType, int $entityId, ?string $field, ?string $locale, DateTimeImmutable $since): ?array
    {
        $sql = 'SELECT actor, occurred_at, source FROM admin_audit_entry WHERE entity_type = ? AND entity_id = ? AND occurred_at >= ?';
        $params = [$entityType, $entityId, $since->format('Y-m-d H:i:s')];
        if (null !== $field) {
            $sql .= ' AND field = ?';
            $params[] = $field;
        }
        if (null !== $locale) {
            $sql .= ' AND locale = ?';
            $params[] = $locale;
        }
        $row = $this->db->fetchAssociative($sql.' ORDER BY occurred_at DESC, id DESC LIMIT 1', $params);
        if (false === $row) {
            return null;
        }

        return ['changed_by' => null === $row['actor'] ? null : (string) $row['actor'], 'changed_at' => (string) $row['occurred_at'], 'source' => (string) $row['source']];
    }
}
