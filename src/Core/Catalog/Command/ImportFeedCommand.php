<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Command;

use App\Core\Catalog\Import\Exception\ImportAlreadyRunningException;
use App\Core\Catalog\Import\Exception\UnsafeFeedException;
use App\Core\Catalog\Import\Feed\FeedImportAdapter;
use App\Core\Catalog\Import\Feed\FeedOptions;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Імпорт фіду постачальника. За замовчуванням чанки йдуть у Messenger (воркер обробляє), --sync — усе тут і зараз.
 * Джерело (--source) — код постачальника: за ним зберігаються ExternalRef карток і рахуються «зниклі з фіду».
 */
#[AsCommand(name: 'catalog:import:feed', description: 'Імпортувати фід постачальника (YML/XML або CSV, файл чи URL)')]
final class ImportFeedCommand
{
    public function __construct(private readonly FeedImportAdapter $adapter)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Шлях до файлу або URL')] string $feed,
        #[Option(description: 'Код джерела (snake_case): за ним ведеться облік карток')] string $source = 'default',
        #[Option(description: 'auto | yml | csv')] string $format = 'auto',
        #[Option(description: 'Обробити чанки одразу, без Messenger')] bool $sync = false,
        #[Option(description: 'Рядків у чанку')] int $chunk = 500,
        #[Option(description: 'Валюта, якщо фід її не вказує')] string $currency = 'UAH',
        #[Option(description: 'Локаль назв без суфікса мови')] string $locale = 'uk',
        #[Option(description: 'Роздільник CSV (за замовчуванням визначається)')] ?string $delimiter = null,
    ): int {
        $io->title(\sprintf('Імпорт фіду %s → джерело %s%s', $feed, $source, $sync ? ' (sync)' : ''));
        try {
            $run = $this->adapter->import($feed, $source, new FeedOptions($currency, $locale, $delimiter), $format, $chunk, $sync);
        } catch (ImportAlreadyRunningException $e) {
            $io->warning($e->getMessage());

            return Command::FAILURE;
        } catch (UnsafeFeedException $e) {
            // DOCTYPE/ENTITY у XML, не http(s), понад ліміт розміру: фід відхилено, нічого не записано
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(...array_map(static fn (string $k, int|string|null $v): array => [$k => $v], array_keys($run->summary()), array_values($run->summary())));
        if ([] !== $run->getErrors()) {
            $io->section(\sprintf('Помилки (%d, перші 20)', \count($run->getErrors())));
            $io->listing(\array_slice($run->getErrors(), 0, 20));
        }
        if (!$sync && $run->getTotalChunks() > 0 && $run->getProcessedChunks() < $run->getTotalChunks()) {
            $io->note('Чанки поставлено в Messenger (async): звіт добереться після обробки воркером — див. catalog_import_run.');
        }
        $io->success('Готово');

        return Command::SUCCESS;
    }
}
