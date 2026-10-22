<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Command;

use App\Core\Integration\Crm\CrmConnectorRegistry;
use App\Core\Integration\Crm\StockApplyReport;
use App\Core\Integration\Crm\StockLevelApplier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Звірка залишків з CRM (контракт §3): окремий CLI з lock, не Messenger — на 500k SKU це години під rate limit.
 * Пакетами по --batch, щоб транзакції були короткими; --dry-run лише рахує розбіжності.
 */
#[AsCommand(name: 'crm:stock:reconcile', description: 'Звірити залишки варіантів з активною CRM (pullStock)')]
final class StockReconcileCommand
{
    public function __construct(
        private readonly CrmConnectorRegistry $connectors,
        private readonly StockLevelApplier $applier,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Лише порахувати, нічого не писати')] bool $dryRun = false,
        #[Option(description: 'Скільки рівнів застосовувати одним flush')] int $batch = 200,
        #[Option(description: 'Обмежити цими sku (через кому)')] ?string $skus = null,
    ): int {
        $connector = $this->connectors->active();
        $lock = $this->lockFactory->createLock('crm-stock-reconcile:'.$connector->getSystem(), 3600);
        if (!$lock->acquire()) {
            $io->warning('Звірка вже виконується — вихід');

            return Command::SUCCESS;
        }

        try {
            $io->title(\sprintf('Звірка залишків: %s%s', $connector->getSystem(), $dryRun ? ' (dry-run)' : ''));
            $wanted = null === $skus ? [] : array_values(array_filter(array_map(trim(...), explode(',', $skus))));

            $total = new StockApplyReport();
            $buffer = [];
            foreach ($connector->pullStock($wanted) as $level) {
                $buffer[] = $level;
                if (\count($buffer) >= max(1, $batch)) {
                    $total->merge($this->applier->apply($connector->getSystem(), $buffer, flush: !$dryRun));
                    $buffer = [];
                }
            }
            if ([] !== $buffer) {
                $total->merge($this->applier->apply($connector->getSystem(), $buffer, flush: !$dryRun));
            }

            $io->definitionList(
                ['Змінено' => $total->changed],
                ['Без змін' => $total->unchanged],
                ['Обрізано до 0' => $total->clamped],
                ['Невідомих sku' => \count($total->unknownSkus)],
            );
            if ([] !== $total->unknownSkus) {
                $io->listing(\array_slice($total->unknownSkus, 0, 20));
            }
            $io->success($dryRun ? 'Розбіжності пораховано, БД не змінено' : 'Залишки звірено');

            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
