<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Crm;

use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Integration\Entity\ExternalRef;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Спільна для всіх CRM частина (ADR Rev 3): StockLevel → ProductVariant.stock. Варіант шукається за ExternalRef
 * (system, product_variant, externalId), потім за sku; знайдений за sku з відомим externalId — запам'ятовується
 * в ExternalRef. Ідемпотентно: той самий рівень двічі не змінює нічого. Невідомий sku — у звіт і лог, не виняток.
 */
final class StockLevelApplier
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly LoggerInterface $logger)
    {
    }

    /** @param iterable<StockLevel> $levels */
    public function apply(string $system, iterable $levels, bool $flush = true): StockApplyReport
    {
        $report = new StockApplyReport();
        $variantRepo = $this->em->getRepository(ProductVariant::class);
        $refRepo = $this->em->getRepository(ExternalRef::class);

        foreach ($levels as $level) {
            $variant = null;
            $ref = null;
            if (null !== $level->externalId) {
                $ref = $refRepo->findOneBy(['system' => $system, 'entityType' => ExternalRef::TYPE_PRODUCT_VARIANT, 'externalId' => $level->externalId]);
                if (null !== $ref) {
                    $variant = $variantRepo->find($ref->getEntityId());
                }
            }
            $variant ??= $variantRepo->findOneBy(['sku' => $level->sku]);
            if (null === $variant) {
                $report->unknownSkus[] = $level->sku;
                $this->logger->warning('CRM stock: невідомий sku', ['system' => $system, 'sku' => $level->sku, 'externalId' => $level->externalId]);
                continue;
            }

            if ($level->clamped) {
                ++$report->clamped;
                $this->logger->notice('CRM stock: in_stock − in_reserve < 0, записано 0', ['sku' => $level->sku, 'inStock' => $level->inStock, 'inReserve' => $level->inReserve]);
            }

            if ($variant->getStock() === $level->available) {
                ++$report->unchanged;
            } else {
                $variant->setStock($level->available);
                ++$report->changed;
            }

            if (null !== $level->externalId && null === $ref && null !== $variant->getId()) {
                $this->em->persist(new ExternalRef($system, ExternalRef::TYPE_PRODUCT_VARIANT, $variant->getId(), $level->externalId));
                ++$report->refsLearned;
            }
        }

        if ($flush) {
            // У транзакції: postFlush-слухач індексу фільтрів робить UPDATE catalog_filter_variant у тій самій транзакції, що й залишок (ADR Rev 9)
            $this->em->wrapInTransaction(fn () => $this->em->flush());
        }

        return $report;
    }
}
