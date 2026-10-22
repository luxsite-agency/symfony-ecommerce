<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\FilterIndex;

use App\Core\Catalog\Query\CatalogQuery;
use App\Core\Catalog\Query\CategorySummaryStore;
use Doctrine\DBAL\Connection;

/**
 * Перерахунок зведення фасетів категорій (ADR Rev 11 п. 4): завжди ціла категорія тими самими запитами, що й лістинг
 * (CatalogQuery::summarize), без дельт. Повний — після перебудови індексу; фоновий — для категорій із CategoryFacetQueue.
 */
final class CategorySummaryRefresher
{
    public function __construct(
        private readonly Connection $db,
        private readonly CatalogQuery $catalog,
        private readonly CategorySummaryStore $store,
        private readonly CategoryFacetQueue $queue,
    ) {
    }

    public function refresh(int $categoryId): void
    {
        [$total, $facets] = $this->catalog->summarize($categoryId);
        $this->store->save($categoryId, $total, $facets);
    }

    /**
     * Усі категорії; позначки черги, зроблені до старту, знімаються (їх уже враховано), зведення видалених — прибираються.
     *
     * @param callable(int, int): void|null $progress (порахованих, усього)
     *
     * @return int категорій
     */
    public function refreshAll(?callable $progress = null): int
    {
        $started = (string) $this->db->fetchOne('SELECT NOW(6)');
        /** @var list<int|string> $ids */
        $ids = $this->db->fetchFirstColumn('SELECT id FROM catalog_category ORDER BY id');
        $ids = array_map(intval(...), $ids);
        foreach ($ids as $i => $id) {
            $this->refresh($id);
            if (null !== $progress) {
                $progress($i + 1, \count($ids));
            }
        }
        $this->store->deleteExcept($ids);
        $this->queue->clearQueuedBefore($started);

        return \count($ids);
    }

    /** Категорії з черги, що відлежали затримку; кожну забирає з черги перед перерахунком лише один процес. */
    public function refreshQueued(): int
    {
        $done = 0;
        foreach ($this->queue->ready() as $categoryId) {
            if ($this->queue->take($categoryId)) {
                $this->refresh($categoryId);
                ++$done;
            }
        }

        return $done;
    }
}
