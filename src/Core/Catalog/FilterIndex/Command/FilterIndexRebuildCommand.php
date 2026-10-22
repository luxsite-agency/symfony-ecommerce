<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\FilterIndex\Command;

use App\Core\Catalog\FilterIndex\FilterIndexRebuilder;
use App\Core\Catalog\FilterIndex\FilterIndexState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Повна перебудова read-index фільтрів (ADR Rev 9): shadow-таблиці + RENAME TABLE; довга — запускати в tmux. */
#[AsCommand(name: 'catalog:filter-index:rebuild', description: 'Повністю перебудувати read-index фільтрів (shadow-таблиці + атомарний RENAME TABLE)')]
final class FilterIndexRebuildCommand
{
    public function __construct(private readonly FilterIndexRebuilder $rebuilder, private readonly FilterIndexState $state)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Товарів (за id) в одній транзакції заповнення')] int $batch = 5000,
    ): int {
        if ($batch < 1) {
            $io->error('--batch має бути ≥ 1');

            return Command::INVALID;
        }
        if ($this->state->isRebuilding()) {
            $io->warning(\sprintf('Прапорець перебудови вже стоїть (з %s): або йде інша перебудова, або попередня впала до зняття прапорця. Lock вирішить.', $this->state->rebuildStartedAt()?->format('c') ?? '?'));
        }
        $io->title('Перебудова індексу фільтрів');
        $result = $this->rebuilder->rebuild($batch, static function (string $stage, array $data) use ($io): void {
            if ('batch' === $stage && $io->isVerbose()) {
                $io->writeln(\sprintf('  id %d–%d з %d, варіантів %d', $data['from'], $data['to'], $data['max'], $data['variants']));
            }
            if ('indexes' === $stage) {
                $io->writeln(\sprintf('  заповнення %.1f с, побудова вторинних індексів %.1f с', $data['fill_seconds'], $data['index_seconds']));
            }
        });
        $io->definitionList(...array_map(static fn (string $k, int|float $v): array => [$k => $v], array_keys($result), array_values($result)));
        $io->success(\sprintf('Готово за %.1f с', $result['seconds']));

        return Command::SUCCESS;
    }
}
