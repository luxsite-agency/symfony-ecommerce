<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shipping\NovaPoshta;

use App\Core\Shipping\NovaPoshta\Entity\NovaPoshtaWarehouse;
use App\Core\Shipping\NovaPoshta\Exception\NovaPoshtaApiException;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockFactory;

/**
 * Повна синхронізація довідників НП у локальні таблиці: посторінково з API → upsert пакетом на сторінку → після
 * успішного проходу видалення записів, яких API більше не віддає (updated_at старіший за старт). Збій посередині
 * лишає старі дані цілими (видалення не доходить). Lock nova-poshta-sync — проти паралельного запуску (Scheduler + команда).
 */
final class NovaPoshtaDirectorySync
{
    public const string LOCK = 'nova-poshta-sync';

    public function __construct(
        private readonly NovaPoshtaClient $client,
        private readonly Connection $db,
        private readonly LockFactory $locks,
        #[Autowire('%app.nova_poshta_page_size%')] private readonly int $pageSize = 1000,
    ) {
    }

    /** @throws LockConflictedException якщо синхронізація вже йде */
    public function run(): NovaPoshtaSyncReport
    {
        $lock = $this->locks->createLock(self::LOCK, 1800);
        if (!$lock->acquire()) {
            throw new LockConflictedException('Синхронізація довідників Нової пошти вже виконується');
        }
        $started = microtime(true);
        // мітка запуску має бути строго новішою за все, що вже в таблицях (DATETIME без частки секунди; два запуски в одну секунду)
        $now = new DateTimeImmutable((new DateTimeImmutable())->format('Y-m-d H:i:s')); // без мікросекунд, як у колонці
        foreach (['shipping_np_city', 'shipping_np_warehouse'] as $table) {
            $latest = $this->db->fetchOne(\sprintf('SELECT MAX(updated_at) FROM %s', $table));
            if (\is_string($latest) && new DateTimeImmutable($latest) >= $now) {
                $now = (new DateTimeImmutable($latest))->modify('+1 second');
            }
        }
        $stamp = $now->format('Y-m-d H:i:s');
        try {
            $cities = $this->syncCities($stamp);
            $warehouses = $this->syncWarehouses($stamp);
            if (0 === $cities || 0 === $warehouses) {
                throw new NovaPoshtaApiException('Нова пошта повернула порожній довідник — старі дані залишено');
            }
            $removedWarehouses = (int) $this->db->executeStatement('DELETE FROM shipping_np_warehouse WHERE updated_at < ?', [$stamp]);
            $removedCities = (int) $this->db->executeStatement('DELETE FROM shipping_np_city WHERE updated_at < ?', [$stamp]);

            return new NovaPoshtaSyncReport($cities, $warehouses, $removedCities, $removedWarehouses, round(microtime(true) - $started, 1));
        } finally {
            $lock->release();
        }
    }

    private function syncCities(string $stamp): int
    {
        $total = 0;
        for ($page = 1;; ++$page) {
            $rows = $this->client->cities($page, $this->pageSize);
            $values = [];
            foreach ($rows as $row) {
                if (!isset($row['Ref'], $row['Description'])) {
                    continue;
                }
                $values[] = [(string) $row['Ref'], self::cut((string) $row['Description'], 190), self::cut((string) ($row['DescriptionRu'] ?? $row['Description']), 190), self::cut((string) ($row['AreaDescription'] ?? ''), 120), self::cut((string) ($row['SettlementTypeDescription'] ?? ''), 60), $stamp];
            }
            $total += $this->upsert('shipping_np_city', ['ref', 'name', 'name_ru', 'area', 'settlement_type', 'updated_at'], $values);
            if (\count($rows) < $this->pageSize) {
                return $total;
            }
        }
    }

    private function syncWarehouses(string $stamp): int
    {
        $total = 0;
        for ($page = 1;; ++$page) {
            $rows = $this->client->warehouses($page, $this->pageSize);
            $values = [];
            foreach ($rows as $row) {
                if (!isset($row['Ref'], $row['CityRef'], $row['Description'])) {
                    continue;
                }
                $category = strtolower((string) ($row['CategoryOfWarehouse'] ?? 'Branch'));
                $values[] = [
                    (string) $row['Ref'],
                    (string) $row['CityRef'],
                    self::cut((string) ($row['Number'] ?? ''), 20),
                    self::cut((string) $row['Description'], 255),
                    self::cut((string) ($row['DescriptionRu'] ?? $row['Description']), 255),
                    self::cut((string) ($row['ShortAddress'] ?? ''), 255),
                    \in_array($category, [NovaPoshtaWarehouse::TYPE_BRANCH, NovaPoshtaWarehouse::TYPE_POSTOMAT], true) ? $category : self::cut($category, 20),
                    'Working' === ($row['WarehouseStatus'] ?? 'Working') ? 1 : 0,
                    $stamp,
                ];
            }
            $total += $this->upsert('shipping_np_warehouse', ['ref', 'city_ref', 'number', 'name', 'name_ru', 'short_address', 'type', 'active', 'updated_at'], $values);
            if (\count($rows) < $this->pageSize) {
                return $total;
            }
        }
    }

    /**
     * @param list<string>           $columns
     * @param list<list<string|int>> $values
     */
    private function upsert(string $table, array $columns, array $values): int
    {
        if ([] === $values) {
            return 0;
        }
        $row = '('.implode(', ', array_fill(0, \count($columns), '?')).')';
        $updates = implode(', ', array_map(static fn (string $c): string => \sprintf('%1$s = VALUES(%1$s)', $c), \array_slice($columns, 1)));
        $sql = \sprintf('INSERT INTO %s (%s) VALUES %s ON DUPLICATE KEY UPDATE %s', $table, implode(', ', $columns), implode(', ', array_fill(0, \count($values), $row)), $updates);
        $this->db->executeStatement($sql, array_merge(...$values));

        return \count($values);
    }

    private static function cut(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }
}
