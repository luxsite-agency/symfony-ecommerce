<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * Номер замовлення YYMM-NNNNNN з глобального лічильника на період (ADR D6): атомарний upsert з LAST_INSERT_ID —
 * два одночасні оформлення не отримають один номер і не зайдуть у deadlock. Викликати всередині транзакції.
 */
final class OrderNumberGenerator
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function next(?DateTimeImmutable $now = null): string
    {
        $period = ($now ?? new DateTimeImmutable())->format('ym');
        // Одна інструкція з X-lock на рядок (консиліум PR #6 п. 2): INSERT IGNORE + SELECT … FOR UPDATE давали deadlock
        // (S-lock дубльованого ключа проти X-lock) на кожній парі одночасних оформлень. affected: 1 — новий період, 2 — інкремент.
        $affected = (int) $this->db->executeStatement(
            'INSERT INTO sales_order_number_counter (period, last) VALUES (?, 1) ON DUPLICATE KEY UPDATE last = LAST_INSERT_ID(last + 1)',
            [$period],
        );
        $next = 1 === $affected ? 1 : (int) $this->db->fetchOne('SELECT LAST_INSERT_ID()');

        return \sprintf('%s-%06d', $period, $next);
    }
}
