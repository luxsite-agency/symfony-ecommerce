<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Command;

use App\Core\Catalog\FilterIndex\FilterIndexRebuilder;
use App\Core\Catalog\Import\ProductImporter;
use App\Core\Catalog\Synthetic\SyntheticCatalogGenerator;
use App\Core\Catalog\Synthetic\SyntheticCatalogSpec;
use App\Core\Catalog\Synthetic\SyntheticImportRows;
use App\Core\Catalog\Synthetic\SyntheticSqlWriter;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Синтетичний каталог для benchmark (протокол Ф1: 500k карток × 10 характеристик, 463 категорії глибини 3, Zipf,
 * ~25 % OOS), детермінований за --seed.
 *
 * - `--mode=sql` (типово): прямий масовий запис пакетами (SyntheticSqlWriter) — пам'ять не залежить від обсягу,
 *   500k за хвилини; потрібен порожній каталог або --purge (лише dev/test). Далі — повна перебудова індексу фільтрів.
 * - `--mode=import`: ті самі дані через ProductImporter (staging → ORM → Messenger) — перевірка конвеєра імпорту.
 *
 * Великі обсяги — лише з --no-debug: у debug DBAL-профайлер тримає в пам'яті кожен SQL (так і падав OOM на ~134 картках).
 */
#[AsCommand(name: 'catalog:fixtures:synthetic', description: 'Згенерувати синтетичний каталог для benchmark (детермінований, SQL-пакетами або через імпорт)')]
final class SyntheticFixturesCommand
{
    private const int DEBUG_LIMIT = 5000;

    /** @param list<string> $locales */
    public function __construct(
        private readonly SyntheticSqlWriter $writer,
        private readonly ProductImporter $importer,
        private readonly FilterIndexRebuilder $rebuilder,
        #[Autowire('%app.locales%')] private readonly array $locales,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Скільки карток')] int $products = 1000,
        #[Option(description: 'Максимум варіантів на картку (вісь «розмір»; 1 — без варіантів)')] int $variantsPerProduct = 3,
        #[Option(description: 'Скільки категорій (дерево глибини 3)')] int $categories = 463,
        #[Option(description: 'Карткових характеристик')] int $attributes = 10,
        #[Option(description: 'Скільки брендів')] int $brands = 300,
        #[Option(description: 'Seed генератора')] int $seed = 1,
        #[Option(description: 'sql (масовий запис) або import (через ProductImporter)')] string $mode = 'sql',
        #[Option(description: 'Карток у пакеті (sql) / рядків у чанку (import)')] int $batch = 2000,
        #[Option(description: 'Очистити каталог перед записом (TRUNCATE, лише dev/test)')] bool $purge = false,
        #[Option(description: 'Не перебудовувати індекс фільтрів після запису (sql)')] bool $skipIndex = false,
        #[Option(description: 'import: чанки через Messenger, не одразу')] bool $async = false,
    ): int {
        try {
            $spec = new SyntheticCatalogSpec($products, $variantsPerProduct, $categories, $attributes, $brands, $seed);
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        if (!\in_array($mode, ['sql', 'import'], true)) {
            $io->error('--mode: sql або import');

            return Command::INVALID;
        }
        if ($this->debug && $products > self::DEBUG_LIMIT) {
            $io->error(\sprintf('Більше %d карток — лише з --no-debug: у debug профайлер DBAL тримає кожен SQL у пам\'яті (OOM).', self::DEBUG_LIMIT));

            return Command::INVALID;
        }
        if ($purge && 'prod' === $this->environment) {
            $io->error('--purge заборонено в prod');

            return Command::INVALID;
        }

        $generator = new SyntheticCatalogGenerator($spec);
        $io->title(\sprintf('Синтетичний каталог (%s): %d карток, до %d варіантів, %d категорій (%d листів), %d характеристик, %d брендів, seed %d',
            $mode, $products, $variantsPerProduct, \count($generator->categories()), \count($generator->leaves()), $attributes, $brands, $seed));
        $started = microtime(true);
        if ($purge) {
            $this->writer->purge();
            $io->writeln(\sprintf('Каталог очищено за %.1f с', microtime(true) - $started));
        } elseif ('sql' === $mode) {
            try {
                $this->writer->assertEmpty();
            } catch (RuntimeException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        if ('import' === $mode) {
            $run = $this->importer->import('synthetic', (new SyntheticImportRows($this->locales, $this->defaultLocale))->rows($generator), $batch, !$async);
            $summary = $run->summary();
            $io->definitionList(...array_map(static fn (string $k, int|string|null $v): array => [$k => $v], array_keys($summary), array_values($summary)));
        } else {
            $step = max($batch, (int) (ceil($products / 20 / $batch) * $batch));
            $counts = $this->writer->write($generator, $batch, static function (int $done) use ($io, $step, $products, $started): void {
                if (0 === $done % $step || $done === $products) {
                    $io->writeln(\sprintf('  %d / %d карток, %.1f с, пам\'ять %.0f МБ', $done, $products, microtime(true) - $started, memory_get_usage(true) / 1048576));
                }
            });
            $io->definitionList(...array_map(static fn (string $k, int $v): array => [$k => $v], array_keys($counts), array_values($counts)));
            $io->writeln(\sprintf('Запис: %.1f с', microtime(true) - $started));
            if (!$skipIndex) {
                $result = $this->rebuilder->rebuild();
                $io->writeln(\sprintf('Індекс фільтрів: %d варіантів, %d значень, %d товар×категорія за %.1f с', $result['variants'], $result['values'], $result['categories'], $result['seconds']));
            }
        }
        $io->success(\sprintf('%.1f с, пікова пам\'ять %.0f МБ', microtime(true) - $started, memory_get_peak_usage(true) / 1048576));

        return Command::SUCCESS;
    }
}
