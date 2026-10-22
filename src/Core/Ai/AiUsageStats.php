<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Ai;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/** Зведення журналу ai_usage: витрати за календарний місяць (бюджет), кількість викликів. */
final class AiUsageStats
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** Сума cost за поточний місяць (лише успішні виклики), кредити OpenRouter ≈ USD. */
    public function monthCost(?DateTimeImmutable $now = null): float
    {
        $from = ($now ?? new DateTimeImmutable())->modify('first day of this month midnight');
        $sum = $this->db->fetchOne('SELECT COALESCE(SUM(cost), 0) FROM ai_usage WHERE status = ? AND occurred_at >= ?', [AiUsageStatus::Ok->value, $from->format('Y-m-d H:i:s')]);

        return (float) $sum;
    }

    /**
     * noCost — успішні виклики без usage.cost від провайдера: у ліміті вони не враховані, адмінці про це видно.
     *
     * @return array{calls: int, ok: int, cost: float, noCost: int}
     */
    public function monthSummary(?DateTimeImmutable $now = null): array
    {
        $from = ($now ?? new DateTimeImmutable())->modify('first day of this month midnight');
        /** @var array{calls: int|string, ok: int|string|null, cost: string|float|null, no_cost: int|string|null}|false $row */
        $row = $this->db->fetchAssociative("SELECT COUNT(*) AS calls, SUM(status = 'ok') AS ok, COALESCE(SUM(CASE WHEN status = 'ok' THEN cost END), 0) AS cost, SUM(status = 'ok' AND cost IS NULL) AS no_cost FROM ai_usage WHERE occurred_at >= ?", [$from->format('Y-m-d H:i:s')]);

        return false === $row
            ? ['calls' => 0, 'ok' => 0, 'cost' => 0.0, 'noCost' => 0]
            : ['calls' => (int) $row['calls'], 'ok' => (int) ($row['ok'] ?? 0), 'cost' => (float) ($row['cost'] ?? 0), 'noCost' => (int) ($row['no_cost'] ?? 0)];
    }

    /** Прибрати рядки, старші за N місяців; 0 — не прибирати. */
    public function prune(int $months): int
    {
        if ($months <= 0) {
            return 0;
        }

        return (int) $this->db->executeStatement('DELETE FROM ai_usage WHERE occurred_at < NOW() - INTERVAL ? MONTH', [$months]);
    }
}
