<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import;

use App\Core\Catalog\Entity\ImportRun;
use App\Core\Catalog\Enum\ImportRunStatus;
use App\Core\Catalog\FilterIndex\FilterIndexer;
use App\Core\Catalog\Import\Exception\ImportAlreadyRunningException;
use App\Core\Catalog\Import\Exception\InvalidImportRowException;
use App\Core\Catalog\Import\Message\ImportCompleted;
use App\Core\Catalog\Import\Message\ProcessImportChunk;
use App\Core\Catalog\Query\CatalogCacheVersion;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Ідемпотентний імпорт карток (ADR D10): рядки → staging чанками → обробка чанків (Messenger або одразу, --sync) →
 * фінал: варіанти джерела, яких у фіді нема, отримують stock = 0; звіт — ImportRun.
 * Lock catalog-import:<джерело> захищає staging від паралельного запуску; в async-режимі після staging lock
 * відпускається, а від другого запуску джерело береже відкритий (running/finalizing) ImportRun.
 * Фіналізує рівно один: атомарний перехід running → finalizing робить або останній оброблений чанк, або CLI після staging.
 * Sync-запуск, чий процес помер без catch/finally (OOM, kill), наступний імпорт того ж джерела закриває як failed.
 */
final class ProductImporter
{
    public const string SYSTEM_PREFIX = 'feed_';
    public const int LOCK_TTL = 3600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportStaging $staging,
        private readonly ImportChunkProcessor $processor,
        private readonly LockFactory $lockFactory,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly FilterIndexer $filterIndexer,
        private readonly CatalogCacheVersion $cacheVersion,
    ) {
    }

    /** system для ExternalRef карток цього джерела: feed_<джерело> */
    public static function system(string $source): string
    {
        return self::SYSTEM_PREFIX.$source;
    }

    /**
     * @param iterable<ProductImportRow|InvalidImportRowException> $rows рідер може віддати виняток замість рядка — він іде у звіт
     *
     * @throws ImportAlreadyRunningException
     */
    public function import(string $source, iterable $rows, int $chunkSize = 500, bool $sync = false): ImportRun
    {
        $lock = $this->lockFactory->createLock('catalog-import:'.$source, self::LOCK_TTL, autoRelease: false);
        if (!$lock->acquire()) {
            throw new ImportAlreadyRunningException(\sprintf('Імпорт джерела "%s" уже виконується (lock)', $source));
        }
        try {
            foreach ($this->em->getRepository(ImportRun::class)->findBy(['source' => $source]) as $previous) {
                if (!$previous->getStatus()->isOpen()) {
                    continue;
                }
                if ($previous->isSync()) {
                    $this->closeAbandonedRun($previous);
                    continue;
                }
                throw new ImportAlreadyRunningException(\sprintf('Імпорт джерела "%s" ще обробляє чанки запуску #%d', $source, (int) $previous->getId()));
            }

            $run = new ImportRun($source, $sync);
            $this->em->persist($run);
            $this->em->flush();
            $runId = (int) $run->getId();

            try {
                $chunk = [];
                $chunkNo = 0;
                $staged = $invalid = 0;
                $errors = [];
                foreach ($rows as $row) {
                    if ($row instanceof InvalidImportRowException) {
                        ++$invalid;
                        $errors[] = $row->getMessage();
                        continue;
                    }
                    $chunk[] = $row;
                    ++$staged;
                    if (\count($chunk) >= max(1, $chunkSize)) {
                        $this->stageChunk($runId, $chunkNo++, $chunk, $sync);
                        $chunk = [];
                    }
                }
                if ([] !== $chunk) {
                    $this->stageChunk($runId, $chunkNo++, $chunk, $sync);
                }

                $run = $this->reload($runId);
                $run->recordStaged($staged, $invalid, $chunkNo);
                foreach ($errors as $error) {
                    $run->addError($error);
                }
                $this->em->flush();

                if ($this->claimFinalization($runId)) {
                    $this->finalize($runId);
                }

                return $this->reload($runId);
            } catch (Throwable $e) {
                if ($this->em->isOpen()) {
                    $run = $this->reload($runId);
                    $run->fail(\sprintf('%s: %s', $e::class, $e->getMessage()));
                    $this->em->flush();
                } else {
                    // EntityManager закрився після помилки flush: позначити run напряму через DBAL
                    $this->em->getConnection()->executeStatement('UPDATE catalog_import_run SET status = ?, finished_at = NOW() WHERE id = ?', [ImportRunStatus::Failed->value, $runId]);
                    $this->logger->error('Імпорт: запуск провалено, EntityManager закрито', ['run' => $runId, 'error' => $e->getMessage()]);
                }
                throw $e;
            }
        } finally {
            $lock->release();
        }
    }

    /** Один чанк (Messenger-хендлер або inline у --sync); переможець фіналізації — див. claimFinalization(). */
    public function processChunk(int $runId, int $chunkNo): void
    {
        $run = $this->reload($runId);
        if (!$run->getStatus()->isOpen()) {
            $this->logger->warning('Імпорт: чанк для завершеного запуску пропущено', ['run' => $runId, 'chunk' => $chunkNo]);

            return;
        }
        $result = $this->processor->process($run, $chunkNo, self::system($run->getSource()));

        $run = $this->reload($runId); // процесор робить clear()
        $run->recordChunk($result['created'], $result['updated'], $result['unchanged'], $result['repriced']);
        foreach ($result['errors'] as $error) {
            $run->addError($error);
        }
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE catalog_import_run SET processed_chunks = processed_chunks + 1 WHERE id = ?', [$runId]);

        if ($this->claimFinalization($runId)) {
            $this->finalize($runId);
        }
    }

    /** Атомарно running → finalizing, коли всі чанки оброблено; рівно один виклик отримує true. */
    private function claimFinalization(int $runId): bool
    {
        return 1 === $this->em->getConnection()->executeStatement(
            'UPDATE catalog_import_run SET status = ? WHERE id = ? AND status = ? AND processed_chunks = total_chunks',
            [ImportRunStatus::Finalizing->value, $runId, ImportRunStatus::Running->value],
        );
    }

    private function finalize(int $runId): void
    {
        $run = $this->reload($runId);
        $disappeared = $this->staging->zeroDisappearedVariants($runId, self::system($run->getSource()));
        // Batch-reprice і «зникли з фіду» оминають ORM: прапорці/ціни в read-index фільтрів оновити точковим UPDATE
        // по всіх варіантах, яких запуск торкнувся; нові/змінені картки вже пішли в ReindexProducts через listener.
        $this->filterIndexer->refreshVariantsUpdatedSince($run->getStartedAt());
        $this->cacheVersion->bump();
        $run->complete($disappeared);
        $this->em->flush();
        $this->staging->purge($runId);
        $this->bus->dispatch(new ImportCompleted($runId)); // похідні артефакти (Merchant feed) — async
        $this->logger->info('Імпорт завершено', $run->summary());
    }

    /** @param list<ProductImportRow> $chunk */
    private function stageChunk(int $runId, int $chunkNo, array $chunk, bool $sync): void
    {
        $this->staging->insertChunk($runId, $chunkNo, $chunk);
        // total_chunks стає відомим лише після staging, тому чанк не може фіналізувати запуск раніше за import()
        if ($sync) {
            $this->processChunk($runId, $chunkNo);
        } else {
            $this->bus->dispatch(new ProcessImportChunk($runId, $chunkNo));
        }
    }

    /**
     * Sync-запуск тримає lock джерела від старту до фіналу. Якщо lock у нас, а sync-запуск досі running/finalizing —
     * його процес помер, не дійшовши до catch/finally (OOM, fatal, kill -9): закриваємо як failed і прибираємо його
     * staging, інакше джерело заблоковане назавжди. Async-запуск так закрити не можна: його чанки ще можуть бути в черзі.
     */
    private function closeAbandonedRun(ImportRun $run): void
    {
        $run->fail('Процес запуску завершився аварійно (OOM, fatal або kill) — запуск закрито наступним імпортом');
        $this->em->flush();
        $this->staging->purge((int) $run->getId());
        $this->logger->warning('Імпорт: закрито покинутий sync-запуск', ['run' => $run->getId(), 'source' => $run->getSource()]);
    }

    private function reload(int $runId): ImportRun
    {
        $run = $this->em->find(ImportRun::class, $runId) ?? throw new RuntimeException(\sprintf('ImportRun #%d не знайдено', $runId));
        $this->em->refresh($run);

        return $run;
    }
}
