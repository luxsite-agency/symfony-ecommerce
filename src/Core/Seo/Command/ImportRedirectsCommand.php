<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo\Command;

use App\Core\Seo\RedirectCsv;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Великі мапи редиректів (понад RedirectCsv::WEB_MAX_ROWS) — без ліміту рядків і часу запиту; формат як в адмінці. */
#[AsCommand(name: 'seo:redirects:import', description: 'Імпорт правил редиректів з CSV source;code;target (без ліміту рядків)')]
final class ImportRedirectsCommand
{
    public function __construct(private readonly RedirectCsv $csv)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Argument(description: 'Шлях до CSV (UTF-8, роздільник «;»)')] string $file): int
    {
        if (!is_file($file) || !is_readable($file)) {
            $io->error(\sprintf('Файл %s не знайдено або не читається', $file));

            return Command::FAILURE;
        }
        $report = $this->csv->import($file, null);
        if ([] !== $report['errors']) {
            $io->table(['рядок', 'помилка'], array_map(static fn (array $e): array => [$e['line'], $e['message']], $report['errors']));
        }
        $io->success(\sprintf('Створено: %d, замінено: %d, з помилками: %d', $report['created'], $report['updated'], \count($report['errors'])));

        return [] === $report['errors'] ? Command::SUCCESS : Command::FAILURE;
    }
}
