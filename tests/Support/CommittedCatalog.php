<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\DBAL\Connection;

/**
 * Товари для тестів пошуку, ЗАКОМІЧЕНІ в БД: InnoDB FULLTEXT не бачить незакомічених рядків, а DAMA тримає кожен тест у
 * транзакції. Пишемо сирим SQL з id від BASE_ID (не перетинаються з фікстурами) ПЕРШИМ ділом у тесті (коміт забере й те, що
 * тест уже записав), комітимо, відкриваємо нову транзакцію DAMA; purge() у tearDown відкочує решту даних тесту, видаляє ці
 * товари (каскадом переклади й варіанти) і комітить лише видалення. Викликати purge() у tearDown обовʼязково.
 */
final class CommittedCatalog
{
    public const int BASE_ID = 8_000_000;
    private const int BATCH = 1000;

    /**
     * @param array<int, array{names: array<string, string>, sku: string, barcode?: ?string, brand?: ?int, active?: bool}> $products id → картка
     * @param array<int, string>                                                                                           $brands   id → назва
     */
    public static function insert(Connection $db, array $products, array $brands = []): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($brands as $id => $name) {
            $db->executeStatement('INSERT INTO catalog_brand (id, name, slug, active) VALUES (?, ?, ?, 1)', [$id, $name, 'tst-brand-'.$id]);
        }
        foreach (array_chunk($products, self::BATCH, true) as $chunk) {
            $p = $t = $v = [];
            $pp = $tp = $vp = [];
            foreach ($chunk as $id => $spec) {
                $p[] = '(?, ?, ?, ?, ?, ?, ?)';
                array_push($pp, $id, ($spec['active'] ?? true) ? 1 : 0, $now, 'goods', 'fixed', $now, $spec['brand'] ?? null);
                foreach ($spec['names'] as $locale => $name) {
                    $t[] = '(?, ?, ?, ?)';
                    array_push($tp, $id, $locale, $name, 'tst-'.$id.'-'.$locale);
                }
                $v[] = '(?, ?, ?, 1, ?, ?, 1, 0, 10000, 1, 5, ?, ?, ?, ?)';
                array_push($vp, $id, $id, $spec['sku'], $now, 'UAH', 'in_stock', 'pc', $now, $spec['barcode'] ?? null);
            }
            $db->executeStatement('INSERT INTO catalog_product (id, active, created_at, kind, price_mode, updated_at, brand_id) VALUES '.implode(',', $p), $pp);
            $db->executeStatement('INSERT INTO catalog_product_translation (product_id, locale, name, slug) VALUES '.implode(',', $t), $tp);
            $db->executeStatement('INSERT INTO catalog_product_variant (id, product_id, sku, active, created_at, currency, min_quantity, position, price_minor, quantity_step, stock, stock_policy, unit, updated_at, barcode) VALUES '.implode(',', $v), $vp);
        }
        self::commit();
    }

    /**
     * Відкочує дані самого тесту (як DAMA), тоді видаляє закомічені товари й бренди і комітить лише це видалення.
     *
     * @param list<int> $brandIds
     */
    public static function purge(Connection $db, array $brandIds = []): void
    {
        StaticDriver::rollBack();
        StaticDriver::beginTransaction();
        $db->executeStatement('DELETE FROM catalog_product WHERE id >= ?', [self::BASE_ID]);
        if ([] !== $brandIds) {
            $db->executeStatement('DELETE FROM catalog_brand WHERE id IN (?)', [$brandIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }
        self::commit();
    }

    private static function commit(): void
    {
        StaticDriver::commit();
        StaticDriver::beginTransaction();
    }
}
