<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\FilterIndex;

use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Catalog\Query\CatalogHit;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Запис у read-index фільтрів (ADR Rev 9): рядок на варіант, карткові характеристики скопійовані в кожен варіант,
 * лише Attribute.filterable; товар × (категорія ∪ усі предки). Джерело правди — OLTP-таблиці каталогу, індекс
 * будується тільки SQL-ом (INSERT … SELECT), без гідратації сутностей.
 *
 * - reindexProducts(): DELETE + INSERT рядків товарів в одній транзакції (регенерація по product_id).
 * - refreshVariants()/refreshProductVariants()/refreshVariantsUpdatedSince(): точковий UPDATE прапорців/ціни/бренду
 *   (вебхук залишку, імпортний batch-reprice, зміна active/price_mode товару) без перезапису значень.
 * - Під час повної перебудови кожен запис у live дублюється в dirty-список у тій самій транзакції; прапорець читається
 *   з FOR SHARE (FilterIndexState::isRebuildingLocked), тож перемикання прапорця перебудовою чекає на писачів у польоті
 *   і гонки з RENAME TABLE немає (див. FilterIndexRebuilder).
 * - catalog_filter_product_category несе ключі сторінки категорії (ADR Rev 11 п. 3): listed і sort_asc/sort_desc —
 *   мін. ціна listed-варіантів із різними сентинелами «ціни за запитом». refreshWhere оновлює їх лише для товарів, у яких
 *   змінились ціна, listed або price_mode (вебхук залишку pc не чіпає).
 * - Кожен запис у live позначає категорії товарів у CategoryFacetQueue (фоновий перерахунок зведення фасетів, п. 4);
 *   повідомлення шлеться після коміту.
 */
final class FilterIndexer
{
    /** Ключ сортування: мін. ціна серед listed-варіантів; «за запитом» — сентинел; нема listed — теж сентинел. */
    private const string SORT_KEY_SQL = 'COALESCE(MIN(IF(%1$s, IF(%2$s, %4$d, %3$s), NULL)), %4$d)';
    private const string PRICE_MODE_SQL = "CASE p.price_mode WHEN '%s' THEN 0 WHEN '%s' THEN 1 ELSE 2 END";
    private const string LISTED_SQL = '(p.active = 1 AND v.active = 1)';
    // «ціна за запитом» — не purchasable: купити не можна (ProductVariant::isOrderable), лише запитати ціну
    private const string PURCHASABLE_SQL = '(p.active = 1 AND v.active = 1 AND p.price_mode <> \'%2$s\' AND (v.stock_policy <> \'%1$s\' OR v.stock > 0))';
    // in_stock ⊂ listed: вимкнений товар/варіант «в наявності» не показується (уточнення DDL критика, де in_stock без active)
    private const string IN_STOCK_SQL = "(p.active = 1 AND v.active = 1 AND v.stock_policy = '%s' AND v.stock > 0)";
    /** Сентинели ключів сортування «ціна за запитом» — у кінці і при зростанні, і при спаданні (CatalogQuery). */
    public const int SORT_LAST_ASC = 2_147_483_647;
    public const int SORT_LAST_DESC = -1;

    public function __construct(private readonly Connection $db, private readonly FilterIndexState $state, private readonly CategoryFacetQueue $facetQueue)
    {
    }

    /**
     * Регенерація рядків указаних товарів (у транзакції). Товар, якого вже нема, просто втрачає рядки.
     *
     * @param list<int> $productIds
     *
     * @return int кількість записаних рядків варіантів
     */
    public function reindexProducts(array $productIds, ?FilterIndexTables $tables = null): int
    {
        $tables ??= FilterIndexTables::live();
        $productIds = self::ids($productIds);
        if ([] === $productIds) {
            return 0;
        }

        $notify = false;
        $rows = $this->db->transactional(function () use ($productIds, $tables, &$notify): int {
            if ($tables->isLive() && $this->state->isRebuildingLocked()) {
                $this->state->markDirty($productIds);
            }
            // категорії і до, і після: товар могли перенести
            $notify = $tables->isLive() && $this->facetQueue->markProducts($productIds);
            $this->deleteRows($tables, $productIds);
            $rows = $this->insertRows($tables, 'v.product_id IN (?)', [$productIds], [ArrayParameterType::INTEGER]);
            $notify = ($tables->isLive() && $this->facetQueue->markProducts($productIds)) || $notify;

            return $rows;
        });
        if ($notify) {
            $this->facetQueue->dispatch();
        }

        return $rows;
    }

    /**
     * Масовий запис діапазону id товарів (повна перебудова в shadow-таблиці). Без DELETE: таблиці порожні.
     *
     * @return int рядків варіантів
     */
    public function fillProductRange(FilterIndexTables $tables, int $fromId, int $toId): int
    {
        return $this->db->transactional(fn (): int => $this->insertRows($tables, 'v.product_id BETWEEN ? AND ?', [$fromId, $toId], []));
    }

    /** @param list<int> $productIds */
    public function deleteProducts(array $productIds): void
    {
        $productIds = self::ids($productIds);
        if ([] === $productIds) {
            return;
        }
        $notify = $this->db->transactional(function () use ($productIds): bool {
            if ($this->state->isRebuildingLocked()) {
                $this->state->markDirty($productIds);
            }
            $notify = $this->facetQueue->markProducts($productIds);
            $this->deleteRows(FilterIndexTables::live(), $productIds);

            return $notify;
        });
        if ($notify) {
            $this->facetQueue->dispatch();
        }
    }

    /**
     * Точковий UPDATE рядків варіантів: ціна, бренд, price_mode, listed/purchasable/in_stock — після зміни залишку/ціни
     * варіанта чи active/price_mode/brand товару. Значення характеристик не чіпає.
     *
     * @param list<int> $variantIds
     */
    public function refreshVariants(array $variantIds): int
    {
        $variantIds = self::ids($variantIds);
        if ([] === $variantIds) {
            return 0;
        }

        return $this->refreshWhere('fv.variant_id IN (?)', 'v.id IN (?)', [$variantIds], [ArrayParameterType::INTEGER]);
    }

    /** @param list<int> $productIds */
    public function refreshProductVariants(array $productIds): int
    {
        $productIds = self::ids($productIds);
        if ([] === $productIds) {
            return 0;
        }

        return $this->refreshWhere('fv.product_id IN (?)', 'v.product_id IN (?)', [$productIds], [ArrayParameterType::INTEGER]);
    }

    /**
     * Після імпорту: усі варіанти, які джерело чіпало з початку запуску (batch-reprice, «зникли з фіду» → stock 0).
     * updated_at пишуть і ORM (часовий пояс PHP, на dev-сервері Europe/Kyiv), і SQL NOW() (пояс сесії MySQL, UTC) — межу
     * беремо ранішу з двох, із запасом: зайвий UPDATE дешевший за пропущений.
     */
    public function refreshVariantsUpdatedSince(DateTimeImmutable $since): int
    {
        $dbNow = new DateTimeImmutable((string) $this->db->fetchOne('SELECT NOW()'));
        $offset = $dbNow->getTimestamp() - time();
        $bound = min($since, $since->modify(\sprintf('%+d seconds', $offset)))->modify('-5 seconds');

        return $this->refreshWhere('v.updated_at >= ?', 'v.updated_at >= ?', [$bound->format('Y-m-d H:i:s')], []);
    }

    /** @return array{variants: int, values: int, categories: int} */
    public function countRows(?FilterIndexTables $tables = null): array
    {
        $tables ??= FilterIndexTables::live();

        return [
            'variants' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.$tables->variant),
            'values' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.$tables->value),
            'categories' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.$tables->productCategory),
        ];
    }

    /**
     * @param list<mixed>                    $params
     * @param array<int, ArrayParameterType> $types
     */
    private function refreshWhere(string $indexPredicate, string $variantPredicate, array $params, array $types): int
    {
        $notify = false;
        $updated = $this->db->transactional(function () use ($indexPredicate, $variantPredicate, $params, $types, &$notify): int {
            if ($this->state->isRebuildingLocked()) {
                $this->state->markDirtyByVariantPredicate($variantPredicate, $params, $types);
            }

            // Що саме зміниться: будь-яке поле — категорії в чергу зведення; ціна/listed/price_mode — ще й ключі pc
            $changed = $this->db->fetchAllKeyValue(
                'SELECT fv.product_id, MAX(fv.listed <> '.self::LISTED_SQL.' OR fv.price_minor <> v.price_minor OR fv.price_mode <> '.$this->priceModeSql().') AS sort_changed
                 FROM '.FilterIndexTables::VARIANT.' fv
                 JOIN catalog_product_variant v ON v.id = fv.variant_id
                 JOIN catalog_product p ON p.id = v.product_id
                 WHERE '.$indexPredicate.' AND (fv.listed <> '.self::LISTED_SQL.' OR fv.purchasable <> '.$this->purchasableSql().'
                   OR fv.in_stock <> '.$this->inStockSql().' OR fv.price_minor <> v.price_minor OR fv.price_mode <> '.$this->priceModeSql().'
                   OR NOT (fv.brand_id <=> p.brand_id))
                 GROUP BY fv.product_id',
                $params,
                $types,
            );
            if ([] === $changed) {
                return 0;
            }

            $updated = (int) $this->db->executeStatement(
                'UPDATE '.FilterIndexTables::VARIANT.' fv
                 JOIN catalog_product_variant v ON v.id = fv.variant_id
                 JOIN catalog_product p ON p.id = v.product_id
                 SET fv.brand_id = p.brand_id, fv.price_minor = v.price_minor, fv.price_mode = '.$this->priceModeSql().',
                     fv.listed = '.self::LISTED_SQL.', fv.purchasable = '.$this->purchasableSql().', fv.in_stock = '.$this->inStockSql().'
                 WHERE '.$indexPredicate,
                $params,
                $types,
            );
            $this->refreshSortKeys(array_map(intval(...), array_keys(array_filter($changed, static fn (mixed $flag): bool => 1 === (int) $flag))));
            $notify = $this->facetQueue->markProducts(array_map(intval(...), array_keys($changed)));

            return $updated;
        });
        if ($notify) {
            $this->facetQueue->dispatch();
        }

        return $updated;
    }

    /**
     * listed/sort_asc/sort_desc рядків pc товарів — з уже оновлених рядків catalog_filter_variant (live).
     *
     * @param list<int> $productIds
     */
    private function refreshSortKeys(array $productIds): void
    {
        foreach (array_chunk($productIds, 5000) as $chunk) {
            $this->db->executeStatement(
                'UPDATE '.FilterIndexTables::PRODUCT_CATEGORY.' pc
                 JOIN (
                   SELECT product_id, MAX(listed) AS listed,
                          '.\sprintf(self::SORT_KEY_SQL, 'listed = 1', 'price_mode = '.CatalogHit::PRICE_ON_REQUEST, 'price_minor', self::SORT_LAST_ASC).' AS sort_asc,
                          '.\sprintf(self::SORT_KEY_SQL, 'listed = 1', 'price_mode = '.CatalogHit::PRICE_ON_REQUEST, 'price_minor', self::SORT_LAST_DESC).' AS sort_desc
                   FROM '.FilterIndexTables::VARIANT.' WHERE product_id IN (?) GROUP BY product_id
                 ) a ON a.product_id = pc.product_id
                 SET pc.listed = a.listed, pc.sort_asc = a.sort_asc, pc.sort_desc = a.sort_desc',
                [$chunk],
                [ArrayParameterType::INTEGER],
            );
        }
    }

    /**
     * @param array<array-key, int|string> $ids
     *
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_map(intval(...), $ids)));
    }

    /**
     * Рядки товарів з усіх трьох таблиць — лише індексними шляхами. У value нема індексу з product_id першим (і не
     * треба: 7 млн рядків на 500k), тож DELETE … WHERE product_id IN — повний скан: 1,6 с на один товар на 500k.
     * Тому value — через variant (idx_fv_product → PK value), product_category — через idx_fpc_product.
     *
     * @param list<int> $productIds
     */
    private function deleteRows(FilterIndexTables $tables, array $productIds): void
    {
        $this->db->executeStatement(
            'DELETE f FROM '.$tables->value.' f JOIN '.$tables->variant.' v ON v.variant_id = f.variant_id WHERE v.product_id IN (?)',
            [$productIds],
            [ArrayParameterType::INTEGER],
        );
        $this->db->executeStatement('DELETE FROM '.$tables->variant.' WHERE product_id IN (?)', [$productIds], [ArrayParameterType::INTEGER]);
        $this->db->executeStatement('DELETE FROM '.$tables->productCategory.' WHERE product_id IN (?)', [$productIds], [ArrayParameterType::INTEGER]);
    }

    /**
     * Три INSERT … SELECT для товарів за предикатом по catalog_product_variant v. Повертає кількість рядків варіантів.
     *
     * @param list<mixed>                    $params
     * @param array<int, ArrayParameterType> $types
     */
    private function insertRows(FilterIndexTables $tables, string $variantPredicate, array $params, array $types): int
    {
        $variants = (int) $this->db->executeStatement(
            'INSERT INTO '.$tables->variant.' (variant_id, product_id, brand_id, price_minor, price_mode, listed, purchasable, in_stock)
             SELECT v.id, v.product_id, p.brand_id, v.price_minor, '.$this->priceModeSql().',
                    '.self::LISTED_SQL.', '.$this->purchasableSql().', '.$this->inStockSql().'
             FROM catalog_product_variant v JOIN catalog_product p ON p.id = v.product_id
             WHERE '.$variantPredicate,
            $params,
            $types,
        );

        // Карткові характеристики → у кожен варіант (крім осей: їх джерело — VariantAxisValue), потім осі варіанта.
        $this->db->executeStatement(
            'INSERT INTO '.$tables->value.' (variant_id, product_id, attribute_id, option_id, number_value, boolean_value)
             SELECT v.id, v.product_id, pav.attribute_id, COALESCE(pav.option_id, 0), pav.number_value, pav.boolean_value
             FROM catalog_product_variant v
             JOIN catalog_product_attribute_value pav ON pav.product_id = v.product_id
             JOIN catalog_attribute a ON a.id = pav.attribute_id AND a.filterable = 1 AND a.variant_axis = 0
             WHERE '.$variantPredicate,
            $params,
            $types,
        );
        $this->db->executeStatement(
            'INSERT INTO '.$tables->value.' (variant_id, product_id, attribute_id, option_id, number_value, boolean_value)
             SELECT ax.variant_id, v.product_id, ax.attribute_id, ax.attribute_value_id, NULL, NULL
             FROM catalog_product_variant v
             JOIN catalog_product_variant_axis_value ax ON ax.variant_id = v.id
             JOIN catalog_attribute a ON a.id = ax.attribute_id AND a.filterable = 1
             WHERE '.$variantPredicate,
            $params,
            $types,
        );

        // Товар × (категорія ∪ усі предки): предикат категорії в запитах — рівність, не IN(піддерево). Ключі сторінки —
        // агрегат варіантів з OLTP (shadow під час заповнення без вторинних індексів — з нього агрегувати не можна)
        $listed = self::LISTED_SQL;
        $onRequest = \sprintf("p.price_mode NOT IN ('%s', '%s')", PriceMode::Fixed->value, PriceMode::From->value);
        $this->db->executeStatement(
            'INSERT INTO '.$tables->productCategory.' (category_id, product_id, listed, sort_asc, sort_desc)
             WITH RECURSIVE anc AS (
               SELECT c.id AS category_id, c.id AS ancestor_id, c.parent_id FROM catalog_category c
               UNION ALL
               SELECT anc.category_id, c.id, c.parent_id FROM anc JOIN catalog_category c ON c.id = anc.parent_id
             )
             SELECT DISTINCT anc.ancestor_id, pc.product_id, k.listed, k.sort_asc, k.sort_desc
             FROM catalog_product_category pc
             JOIN anc ON anc.category_id = pc.category_id
             JOIN (
               SELECT v.product_id, MAX('.$listed.') AS listed,
                      '.\sprintf(self::SORT_KEY_SQL, $listed, $onRequest, 'v.price_minor', self::SORT_LAST_ASC).' AS sort_asc,
                      '.\sprintf(self::SORT_KEY_SQL, $listed, $onRequest, 'v.price_minor', self::SORT_LAST_DESC).' AS sort_desc
               FROM catalog_product_variant v JOIN catalog_product p ON p.id = v.product_id
               WHERE '.$variantPredicate.' GROUP BY v.product_id
             ) k ON k.product_id = pc.product_id',
            $params,
            $types,
        );

        return $variants;
    }

    private function priceModeSql(): string
    {
        return \sprintf(self::PRICE_MODE_SQL, PriceMode::Fixed->value, PriceMode::From->value);
    }

    private function purchasableSql(): string
    {
        return \sprintf(self::PURCHASABLE_SQL, StockPolicy::InStock->value, PriceMode::OnRequest->value);
    }

    private function inStockSql(): string
    {
        return \sprintf(self::IN_STOCK_SQL, StockPolicy::InStock->value);
    }
}
