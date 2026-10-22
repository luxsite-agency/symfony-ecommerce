<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Feed;

use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Усі мовні фіди всіх каналів (Google Merchant, Meta — FeedChannel; один прохід по каталогу на мову) під одним lock:
 * імпорт, розклад і ручний запуск не пишуть одночасно (другий виклик просто пропускається — фід щойно оновлює інший процес). Під lock спершу прибираються *.tmp, які лишив процес, убитий посеред запису
 * (SIGKILL, OOM, ребут): інший генератор зараз писати не може, тож усі тимчасові файли фіду — сирітські.
 */
final class MerchantFeeds
{
    public const string LOCK = 'merchant-feed:generate';
    private const int LOCK_TTL = 3600;

    public function __construct(
        private readonly MerchantFeedGenerator $generator,
        private readonly MerchantFeedPaths $paths,
        private readonly LockFactory $locks,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string>|null                     $locales  null — усі app.locales
     * @param callable(string, int, int): void|null $progress (мова, оброблено до id, max id)
     *
     * @return list<array{locale: string, items: int, products: int, path: string, paths: array<string, string>, seconds: float}>|null null — уже генерується
     */
    public function generate(?array $locales = null, int $batch = 500, ?callable $progress = null): ?array
    {
        $lock = $this->locks->createLock(self::LOCK, self::LOCK_TTL);
        if (!$lock->acquire()) {
            $this->logger->info('Merchant feed: пропуск, уже генерується іншим процесом');

            return null;
        }
        try {
            foreach ($this->paths->orphanTemps() as $orphan) {
                if (@unlink($orphan)) {
                    $this->logger->warning('Merchant feed: видалено тимчасовий файл перерваної генерації', ['file' => $orphan]);
                }
            }
            $results = [];
            foreach ($locales ?? $this->paths->locales() as $locale) {
                $lock->refresh(); // кожна мова — новий відлік TTL: кілька мов по 500k не переживуть lock
                $results[] = $result = $this->generator->generate($locale, $batch, null === $progress ? null : static fn (int $done, int $max) => $progress($locale, $done, $max));
                $this->logger->info('Товарні фіди згенеровано', $result);
            }

            return $results;
        } finally {
            $lock->release();
        }
    }
}
