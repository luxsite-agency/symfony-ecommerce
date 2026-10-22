<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\FilterIndex;

use App\Core\Catalog\Query\CatalogCacheVersion;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Throwable;

/**
 * Повна перебудова read-index (ADR Rev 9): shadow-таблиці (CREATE TABLE … LIKE), заповнення діапазонами id товарів
 * окремими транзакціями, атомарний RENAME TABLE трьох пар одним оператором, дроп старих.
 * Записи, що прийшли під час перебудови (вебхуки, регенерації), FilterIndexer дублює в dirty-список у своїй транзакції,
 * прочитавши прапорець з FOR SHARE; setRebuilding() тут чекає, доки такі писачі закомітять. Перебудова переграє dirty
 * у shadow перед RENAME і ще раз у live після зняття прапорця (до спорожнення) — після цього нічого не втрачено.
 * RENAME TABLE чекає metadata lock відкритих транзакцій, що писали в live, і на цей час блокує нових читачів індексу.
 * Після swap (ADR Rev 11 п. 2, 4): статистика й гістограми (listed = 98 % варіантів, без гістограми оптимізатор
 * вважав 10 %) і повний перерахунок зведення фасетів усіх категорій.
 */
final class FilterIndexRebuilder
{
    public const string LOCK = 'catalog-filter-index:rebuild';
    private const int LOCK_TTL = 7200;
    private const int DIRTY_BATCH = 1000;
    /** До RENAME dirty-список ще поповнюється — обмежуємо раунди (решта переграється після RENAME). */
    private const int DIRTY_ROUNDS_BEFORE_SWAP = 50;
    /** Після зняття прапорця нових записів у dirty нема — переграємо до кінця (запобіжник від нескінченного циклу). */
    private const int DIRTY_ROUNDS_AFTER_SWAP = 100_000;

    public function __construct(
        private readonly Connection $db,
        private readonly FilterIndexer $indexer,
        private readonly FilterIndexState $state,
        private readonly LockFactory $lockFactory,
        private readonly CatalogCacheVersion $cacheVersion,
        private readonly CategorySummaryRefresher $summaries,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param callable(string, array<string, int|float>): void|null $progress
     *
     * @return array{products: int, batches: int, variants: int, values: int, categories: int, dirty_replayed: int, summaries: int, summary_seconds: float, seconds: float}
     */
    public function rebuild(int $batchSize = 5000, ?callable $progress = null): array
    {
        $lock = $this->lockFactory->createLock(self::LOCK, self::LOCK_TTL, autoRelease: false);
        if (!$lock->acquire()) {
            throw new RuntimeException('Перебудова індексу фільтрів уже виконується (lock '.self::LOCK.')');
        }
        $started = microtime(true);
        $shadow = FilterIndexTables::shadow();
        $old = FilterIndexTables::old();
        $notify = $progress ?? static function (): void {};

        try {
            $this->state->clearAllDirty();
            $this->state->setRebuilding(true);
            $this->dropTables($shadow);
            $this->dropTables($old);
            // Вторинні індекси shadow знімаються на час заповнення й будуються одним ALTER після нього: сортований
            // bulk-build замість вставки мільйонів рядків у три B-дерева вроздріб (500k карток, 7,3 млн рядків value: 994 → 54 с)
            $deferred = [];
            foreach (FilterIndexTables::baseNames() as $i => $base) {
                $table = $shadow->all()[$i];
                $this->db->executeStatement(\sprintf('CREATE TABLE %s LIKE %s', $table, $base));
                $indexes = $this->secondaryIndexes($table);
                if ([] !== $indexes) {
                    $this->db->executeStatement('ALTER TABLE '.$table.' '.implode(', ', array_map(static fn (string $name): string => 'DROP INDEX '.$name, array_keys($indexes))));
                    $deferred[$table] = $indexes;
                }
            }

            [$minId, $maxId] = $this->productIdBounds();
            $batches = $variants = 0;
            for ($from = $minId; $from <= $maxId; $from += $batchSize) {
                $to = min($maxId, $from + $batchSize - 1);
                $variants += $this->indexer->fillProductRange($shadow, $from, $to);
                ++$batches;
                $notify('batch', ['from' => $from, 'to' => $to, 'max' => $maxId, 'variants' => $variants]);
            }
            $filled = microtime(true);
            foreach ($deferred as $table => $indexes) {
                $this->db->executeStatement('ALTER TABLE '.$table.' '.implode(', ', $indexes));
            }
            $notify('indexes', ['fill_seconds' => round($filled - $started, 2), 'index_seconds' => round(microtime(true) - $filled, 2)]);

            $replayed = $this->replayDirty($shadow, self::DIRTY_ROUNDS_BEFORE_SWAP);

            // Атомарний swap: три пари в одному RENAME TABLE — читачі бачать або старий, або новий індекс цілком
            $pairs = [];
            foreach (FilterIndexTables::baseNames() as $i => $base) {
                $pairs[] = \sprintf('%s TO %s, %s TO %s', $base, $old->all()[$i], $shadow->all()[$i], $base);
            }
            $this->db->executeStatement('RENAME TABLE '.implode(', ', $pairs));
            $this->state->setRebuilding(false);

            // Хвіст гонки: хто встиг записати між останнім replay і зняттям прапорця — у dirty, переграти вже в live
            $replayed += $this->replayDirty(FilterIndexTables::live(), self::DIRTY_ROUNDS_AFTER_SWAP);
            $this->dropTables($old);
            $this->analyze();
            $this->cacheVersion->bump();

            $summaryStarted = microtime(true);
            $summaries = $this->summaries->refreshAll();
            $notify('summaries', ['categories' => $summaries, 'summary_seconds' => round(microtime(true) - $summaryStarted, 2)]);
            $this->cacheVersion->bump();

            $counts = $this->indexer->countRows();
            $result = ['products' => $maxId >= $minId ? $this->countProducts() : 0, 'batches' => $batches] + $counts
                + ['dirty_replayed' => $replayed, 'summaries' => $summaries, 'summary_seconds' => round(microtime(true) - $summaryStarted, 2), 'seconds' => round(microtime(true) - $started, 2)];
            $this->logger->info('Індекс фільтрів перебудовано', $result);

            return $result;
        } catch (Throwable $e) {
            // live-таблиці весь час оновлювались напряму — dirty-список без перебудови не потрібен
            try {
                $this->state->setRebuilding(false);
                $this->state->clearAllDirty();
                $this->dropTables($shadow);
            } catch (Throwable) {
            }
            $this->logger->error('Перебудова індексу фільтрів провалилась', ['error' => $e->getMessage()]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Статистика і гістограми нових таблиць: без них оптимізатор оцінює listed=1 як 10 % при 98 % і після перебудови
     * перевертає плани. ANALYZE — на live після RENAME (гістограма прив'язана до імені таблиці).
     */
    private function analyze(): void
    {
        $this->db->fetchAllAssociative('ANALYZE TABLE '.FilterIndexTables::VARIANT.' UPDATE HISTOGRAM ON listed, purchasable, in_stock, price_mode WITH 8 BUCKETS');
        $this->db->fetchAllAssociative('ANALYZE TABLE '.FilterIndexTables::PRODUCT_CATEGORY.' UPDATE HISTOGRAM ON listed WITH 2 BUCKETS');
        $this->db->fetchAllAssociative('ANALYZE TABLE '.FilterIndexTables::VALUE.', '.FilterIndexTables::PRODUCT_CATEGORY);
    }

    /** Переграти dirty-список у вказані таблиці, поки він не спорожніє (обмежена кількість раундів). */
    private function replayDirty(FilterIndexTables $tables, int $maxRounds): int
    {
        $total = 0;
        for ($round = 0; $round < $maxRounds; ++$round) {
            $rows = $this->state->takeDirty(self::DIRTY_BATCH);
            if ([] === $rows) {
                break;
            }
            $this->indexer->reindexProducts(array_column($rows, 'product_id'), $tables);
            $this->state->clearDirty($rows);
            $total += \count($rows);
        }

        return $total;
    }

    /**
     * Вторинні індекси таблиці як фрагменти ALTER: ім'я → «ADD [UNIQUE] INDEX ім'я (колонки)».
     *
     * @return array<string, string>
     */
    private function secondaryIndexes(string $table): array
    {
        $columns = $unique = [];
        foreach ($this->db->fetchAllAssociative('SHOW INDEX FROM '.$table) as $row) {
            $name = (string) $row['Key_name'];
            if ('PRIMARY' === $name) {
                continue;
            }
            $unique[$name] = 0 === (int) $row['Non_unique'];
            $columns[$name][(int) $row['Seq_in_index']] = $row['Column_name'].(null !== $row['Sub_part'] ? '('.(int) $row['Sub_part'].')' : '');
        }
        $ddl = [];
        foreach ($columns as $name => $parts) {
            ksort($parts);
            $ddl[$name] = \sprintf('ADD %sINDEX %s (%s)', $unique[$name] ? 'UNIQUE ' : '', $name, implode(', ', $parts));
        }

        return $ddl;
    }

    private function dropTables(FilterIndexTables $tables): void
    {
        foreach ($tables->all() as $table) {
            $this->db->executeStatement('DROP TABLE IF EXISTS '.$table);
        }
    }

    /** @return array{int, int} */
    private function productIdBounds(): array
    {
        /** @var array{0: int|string|null, 1: int|string|null}|false $row */
        $row = $this->db->fetchNumeric('SELECT MIN(id), MAX(id) FROM catalog_product');
        if (false === $row || null === $row[0]) {
            return [1, 0];
        }

        return [(int) $row[0], (int) $row[1]];
    }

    private function countProducts(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(DISTINCT product_id) FROM '.FilterIndexTables::VARIANT);
    }
}
