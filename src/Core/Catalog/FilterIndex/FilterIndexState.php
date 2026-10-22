<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\FilterIndex;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Стан індексу поза Doctrine: прапорець «іде повна перебудова» (catalog_filter_state) і dirty-список товарів, змінених
 * під час неї (catalog_filter_dirty), який перебудова переграє в shadow до RENAME і в live після (ADR Rev 9).
 * queued_at з мікросекундами: видалення з dirty — лише «того самого» запису (product_id + queued_at), тож повторна
 * позначка між SELECT і DELETE перебудови не губиться.
 */
final class FilterIndexState
{
    public const string STATE = 'catalog_filter_state';
    public const string DIRTY = 'catalog_filter_dirty';

    public function __construct(private readonly Connection $db)
    {
    }

    public function isRebuilding(): bool
    {
        return 1 === (int) $this->db->fetchOne('SELECT rebuilding FROM '.self::STATE.' WHERE id = 1');
    }

    /**
     * Прапорець перебудови з блокуючим читанням (FOR SHARE) — викликати ЛИШЕ всередині транзакції запису в live.
     * S-блокування рядка стану тримається до коміту писача, тож setRebuilding() перебудови (X-блокування) чекає, доки
     * всі писачі, що прочитали старе значення, закомітять: писач або бачить rebuilding=1 і пише в dirty, або
     * встигає закомітити до того, як перебудова почне читати джерело/перейменовувати таблиці.
     */
    public function isRebuildingLocked(): bool
    {
        return 1 === (int) $this->db->fetchOne('SELECT rebuilding FROM '.self::STATE.' WHERE id = 1 FOR SHARE');
    }

    public function rebuildStartedAt(): ?DateTimeImmutable
    {
        $value = $this->db->fetchOne('SELECT rebuild_started_at FROM '.self::STATE.' WHERE id = 1');

        return \is_string($value) ? new DateTimeImmutable($value) : null;
    }

    public function lastRebuiltAt(): ?DateTimeImmutable
    {
        $value = $this->db->fetchOne('SELECT rebuilt_at FROM '.self::STATE.' WHERE id = 1');

        return \is_string($value) ? new DateTimeImmutable($value) : null;
    }

    public function setRebuilding(bool $rebuilding): void
    {
        if ($rebuilding) {
            $this->db->executeStatement('UPDATE '.self::STATE.' SET rebuilding = 1, rebuild_started_at = NOW() WHERE id = 1');
        } else {
            $this->db->executeStatement('UPDATE '.self::STATE.' SET rebuilding = 0, rebuilt_at = NOW() WHERE id = 1');
        }
    }

    /** @param list<int> $productIds */
    public function markDirty(array $productIds): void
    {
        $productIds = array_values(array_unique($productIds));
        if ([] === $productIds) {
            return;
        }
        $this->db->executeStatement(
            'INSERT INTO '.self::DIRTY.' (product_id, queued_at) VALUES '.implode(', ', array_fill(0, \count($productIds), '(?, NOW(6))'))
            .' ON DUPLICATE KEY UPDATE queued_at = NOW(6)',
            $productIds,
        );
    }

    /**
     * Позначити dirty всі товари, що задовольняють SQL-предикат по catalog_product_variant v (значення — лише параметрами).
     *
     * @param list<mixed>                    $params
     * @param array<int, ArrayParameterType> $types
     */
    public function markDirtyByVariantPredicate(string $variantPredicate, array $params = [], array $types = []): void
    {
        $this->db->executeStatement(
            'INSERT INTO '.self::DIRTY.' (product_id, queued_at) SELECT DISTINCT v.product_id, NOW(6) FROM catalog_product_variant v WHERE '.$variantPredicate
            .' ON DUPLICATE KEY UPDATE queued_at = NOW(6)',
            $params,
            $types,
        );
    }

    /** @return list<array{product_id: int, queued_at: string}> */
    public function takeDirty(int $limit): array
    {
        /** @var list<array{product_id: int|string, queued_at: string}> $rows */
        $rows = $this->db->fetchAllAssociative('SELECT product_id, queued_at FROM '.self::DIRTY.' ORDER BY queued_at LIMIT '.max(1, $limit));

        return array_map(static fn (array $r): array => ['product_id' => (int) $r['product_id'], 'queued_at' => (string) $r['queued_at']], $rows);
    }

    /** @param list<array{product_id: int, queued_at: string}> $rows саме ті записи, що були взяті takeDirty() */
    public function clearDirty(array $rows): void
    {
        foreach ($rows as $row) {
            $this->db->executeStatement('DELETE FROM '.self::DIRTY.' WHERE product_id = ? AND queued_at = ?', [$row['product_id'], $row['queued_at']]);
        }
    }

    public function clearAllDirty(): void
    {
        $this->db->executeStatement('DELETE FROM '.self::DIRTY);
    }

    public function countDirty(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.self::DIRTY);
    }
}
