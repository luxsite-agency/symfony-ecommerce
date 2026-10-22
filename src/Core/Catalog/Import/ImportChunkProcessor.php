<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import;

use App\Core\Catalog\Entity\ImportRun;
use App\Core\Catalog\Import\Message\ImportProductMedia;
use App\Core\Integration\Entity\ExternalRef;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Один чанк: (1) batch-UPDATE цін/залишків існуючих варіантів SQL-ом; (2) картки, яких нема або чий contentHash
 * змінився, — через ProductRowApplier (ORM) з flush/clear пакетами. Помилка рядка → у звіт, чанк триває.
 */
final class ImportChunkProcessor
{
    private const int ORM_FLUSH_EVERY = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportStaging $staging,
        private readonly ProductRowApplier $applier,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /** @return array{created: int, updated: int, unchanged: int, repriced: int, errors: list<string>} */
    public function process(ImportRun $run, int $chunkNo, string $system): array
    {
        $runId = (int) $run->getId();
        $repriced = $this->staging->repriceExistingVariants($runId, $chunkNo);
        $errors = [];
        foreach ($this->staging->currencyMismatches($runId, $chunkNo) as $sku) {
            $errors[] = \sprintf('Варіант %s: валюта у фіді не збігається з валютою товару — ціну не оновлено', $sku);
        }

        $knownHashes = $this->knownHashes($runId, $chunkNo, $system);
        $created = $updated = $unchanged = 0;
        $pending = 0;
        $importedAt = $run->getStartedAt();
        $this->applier->resetCache();
        $media = []; // id товару → URL зображень (лише нові/змінені картки — contentHash включає mediaUrls)

        foreach ($this->staging->rowsOfChunk($runId, $chunkNo) as $hash => $row) {
            if (($knownHashes[$row->externalId] ?? null) === $hash) {
                ++$unchanged;
                continue;
            }
            try {
                $result = $this->applier->apply($system, $row, $importedAt);
                $result['created'] ? ++$created : ++$updated;
                foreach ($result['warnings'] as $warning) {
                    $errors[] = $warning;
                }
                if ([] !== $row->mediaUrls) {
                    $media[spl_object_id($result['product'])] = [$result['product'], $row->mediaUrls];
                }
            } catch (Throwable $e) {
                if (!$this->em->isOpen()) {
                    throw $e; // помилка flush закрила EntityManager — далі чанк обробити неможливо
                }
                $errors[] = \sprintf('Картка %s: %s', $row->externalId, $e->getMessage());
                $this->logger->warning('Імпорт: рядок відхилено', ['externalId' => $row->externalId, 'error' => $e->getMessage()]);
                $this->em->clear();
                $this->applier->resetCache();
                $pending = 0;
                $media = array_filter($media, static fn (array $m): bool => null !== $m[0]->getId()); // незбережені відкотились
                continue;
            }
            if (++$pending >= self::ORM_FLUSH_EVERY) {
                $this->em->flush();
                $this->dispatchMedia($media);
                $this->em->clear();
                $this->applier->resetCache();
                $pending = 0;
            }
        }
        $this->em->flush();
        $this->dispatchMedia($media);
        $this->em->clear();

        return ['created' => $created, 'updated' => $updated, 'unchanged' => $unchanged, 'repriced' => $repriced, 'errors' => $errors];
    }

    /**
     * Зображення збережених карток — фоново (ImportProductMedia): завантаження не тримає чанк і його транзакції.
     *
     * @param array<int, array{\App\Core\Catalog\Entity\Product, list<string>}> $media
     */
    private function dispatchMedia(array &$media): void
    {
        foreach ($media as [$product, $urls]) {
            if (null !== $product->getId()) {
                $this->bus->dispatch(new ImportProductMedia((int) $product->getId(), $urls));
            }
        }
        $media = [];
    }

    /**
     * externalId картки → sourceHash товару в БД (для карток чанка, які вже є в цьому джерелі).
     *
     * @return array<string, string|null>
     */
    private function knownHashes(int $runId, int $chunkNo, string $system): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT r.external_id, p.source_hash FROM integration_external_ref r JOIN catalog_product p ON p.id = r.entity_id
             WHERE r.system_code = ? AND r.entity_type = ? AND r.external_id IN (SELECT DISTINCT s.product_external_id FROM '.ImportStaging::TABLE.' s WHERE s.run_id = ? AND s.chunk_no = ?)',
            [$system, ExternalRef::TYPE_PRODUCT, $runId, $chunkNo],
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['external_id']] = null === $row['source_hash'] ? null : (string) $row['source_hash'];
        }

        return $map;
    }
}
