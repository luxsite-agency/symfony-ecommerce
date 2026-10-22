<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\FilterIndex\Command;

use App\Core\Catalog\FilterIndex\CategoryFacetQueue;
use App\Core\Catalog\FilterIndex\CategorySummaryRefresher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Зведення фасетів категорій (ADR Rev 11 п. 4) вручну: черга змінених (як воркер) або всі категорії. Перебудова індексу
 * рахує всі сама; команда — щоб догнати чергу без воркера або після зміни налаштувань каталогу.
 */
#[AsCommand(name: 'catalog:facet-summary:refresh', description: 'Перерахувати зведення фасетів категорій: чергу змінених або всі (--all)')]
final class CategorySummaryRefreshCommand
{
    public function __construct(private readonly CategorySummaryRefresher $refresher, private readonly CategoryFacetQueue $queue)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Усі категорії, а не лише чергу')] bool $all = false): int
    {
        $started = microtime(true);
        if ($all) {
            $count = $this->refresher->refreshAll(static function (int $done, int $total) use ($io): void {
                if ($io->isVerbose()) {
                    $io->writeln(\sprintf('  %d / %d', $done, $total));
                }
            });
        } else {
            $count = $this->refresher->refreshQueued();
        }
        $io->success(\sprintf('Перераховано категорій: %d за %.1f с; у черзі лишилось %d', $count, microtime(true) - $started, $this->queue->count()));

        return Command::SUCCESS;
    }
}
