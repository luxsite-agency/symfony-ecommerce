<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\FilterIndex;

use App\Core\Catalog\FilterIndex\Message\RefreshCategoryFacets;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Черга категорій, чиє зведення фасетів (catalog_filter_category_summary) застаріло (ADR Rev 11 п. 4, без дельт).
 *
 * FilterIndexer позначає категорії змінених товарів (разом із предками — через catalog_filter_product_category) у своїй
 * транзакції; після коміту диспатчить RefreshCategoryFacets із затримкою app.catalog_facet_refresh_delay. Рядок уже
 * в черзі = повідомлення вже в дорозі, тож нове не шлеться: одна категорія перераховується не частіше ніж раз на
 * затримку, скільки б вебхуків не прийшло. Якщо рядок завис (повідомлення загубилось — впав воркер), наступна позначка
 * шле повідомлення знову.
 */
final class CategoryFacetQueue
{
    public const string TABLE = 'catalog_filter_category_dirty';

    public function __construct(
        private readonly Connection $db,
        private readonly MessageBusInterface $bus,
        #[Autowire('%app.catalog_facet_refresh_delay%')] private readonly int $delaySeconds,
    ) {
    }

    /**
     * Позначити категорії товарів (у транзакції писача, до DELETE рядків pc — інакше старі категорії не знайти).
     *
     * @param list<int> $productIds
     *
     * @return bool треба диспатчити RefreshCategoryFacets (після коміту — dispatch())
     */
    public function markProducts(array $productIds): bool
    {
        if ([] === $productIds) {
            return false;
        }
        $inserted = 0;
        foreach (array_chunk($productIds, 5000) as $chunk) {
            $inserted += (int) $this->db->executeStatement(
                'INSERT IGNORE INTO '.self::TABLE.' (category_id, queued_at)
                 SELECT DISTINCT category_id, NOW(6) FROM '.FilterIndexTables::PRODUCT_CATEGORY.' WHERE product_id IN (?)',
                [$chunk],
                [ArrayParameterType::INTEGER],
            );
        }

        return $inserted > 0 || $this->hasStale();
    }

    public function dispatch(): void
    {
        $this->bus->dispatch(new RefreshCategoryFacets(), [new DelayStamp($this->delaySeconds * 1000)]);
    }

    /**
     * Категорії, що відлежали затримку (з допуском 5 с на розбіжність годинників PHP і MySQL).
     *
     * @return list<int>
     */
    public function ready(int $limit = 1000): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->db->fetchFirstColumn(
            \sprintf('SELECT category_id FROM %s WHERE queued_at <= NOW(6) - INTERVAL %d SECOND ORDER BY queued_at LIMIT %d', self::TABLE, max(0, $this->delaySeconds - 5), $limit),
        );

        return array_map(intval(...), $ids);
    }

    /** Забрати категорію з черги перед перерахунком: false — її вже забрав інший процес. */
    public function take(int $categoryId): bool
    {
        return 1 === (int) $this->db->executeStatement('DELETE FROM '.self::TABLE.' WHERE category_id = ?', [$categoryId]);
    }

    /** Після повного перерахунку: позначки, зроблені до нього, уже враховані. */
    public function clearQueuedBefore(string $mysqlDateTime): void
    {
        $this->db->executeStatement('DELETE FROM '.self::TABLE.' WHERE queued_at <= ?', [$mysqlDateTime]);
    }

    public function count(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.self::TABLE);
    }

    /** Рядок старший за подвійну затримку — його повідомлення, найімовірніше, загубилось. */
    private function hasStale(): bool
    {
        return false !== $this->db->fetchOne(\sprintf('SELECT 1 FROM %s WHERE queued_at < NOW(6) - INTERVAL %d SECOND LIMIT 1', self::TABLE, 2 * $this->delaySeconds + 60));
    }
}
