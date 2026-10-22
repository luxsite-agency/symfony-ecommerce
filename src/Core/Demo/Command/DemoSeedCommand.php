<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Demo\Command;

use App\Core\Demo\DemoCatalog;
use App\Core\Demo\DemoPurger;
use App\Core\Demo\DemoSeeder;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Демо-дані для ручної перевірки всього функціоналу на сайті й в адмінці (DemoSeeder): каталог трьох профілів
 * (двері, стелажі й металопрокат, інструмент), контент, три замовлення без CRM і листів, перебудова фільтрів.
 *
 * - повторний запуск нічого не дублює; `--purge` видаляє лише демо-дані (DemoPurger);
 * - у prod — лише з `--force-prod`, а наповнення ще й лише в каталог без не-демо товарів.
 */
#[AsCommand(name: 'app:demo:seed', description: 'Демо-дані магазину (каталог, контент, замовлення) або --purge для їх видалення')]
final class DemoSeedCommand
{
    public function __construct(
        private readonly DemoSeeder $seeder,
        private readonly DemoPurger $purger,
        private readonly Connection $db,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Видалити демо-дані (замовлення покупців, користувачі й налаштування не зачіпаються)')] bool $purge = false,
        #[Option(description: 'Дозволити запуск у prod (технічний домен)')] bool $forceProd = false,
        #[Option(description: 'Без зображень (ні фото з паку, ні плейсхолдерів)')] bool $noImages = false,
        #[Option(description: 'Без демо-замовлень')] bool $noOrders = false,
        #[Option(description: 'Тека зі справжніми фото (<KEY>-<n>.jpg, category-<key>.jpg, post-<key>.jpg); типово app.demo.images_dir')] ?string $imagesDir = null,
    ): int {
        if ('prod' === $this->environment && !$forceProd) {
            $io->error('Це prod: демо-дані запускаються лише з явним --force-prod.');

            return Command::FAILURE;
        }

        if ($purge) {
            try {
                $report = $this->purger->purge();
            } catch (RuntimeException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $this->table($io, $report);
            $io->success([] === $report ? 'Демо-даних не знайдено' : 'Демо-дані видалено');

            return Command::SUCCESS;
        }

        if ('prod' === $this->environment) {
            $foreign = $this->foreignProducts();
            if ($foreign > 0) {
                $io->error(\sprintf('У каталозі %d не-демо товарів: демо-дані в робочий каталог не додаються.', $foreign));

                return Command::FAILURE;
            }
        }

        $report = $this->seeder->seed(!$noImages, !$noOrders, static fn (string $step) => $io->writeln('  '.$step), $imagesDir);
        $this->table($io, $report);
        foreach ($this->seeder->analyticsIds() as $code) {
            $io->warning(\sprintf('Задано %s: на prod теги аналітики виводяться завжди. Для демо-домену очистіть його в адмінці (Налаштування).', $code));
        }
        $io->success(match (true) {
            [] === $report => 'Демо-дані вже є, нічого не додано',
            ['translations_updated'] === array_keys($report) => 'Демо-дані вже є, оновлено переклади',
            default => 'Демо-дані додано',
        });
        $io->note('Назва магазину — параметр app.site_name (config/store/parameters.local.yaml), не налаштування в БД.');

        return Command::SUCCESS;
    }

    /** Товари, у яких є хоч один варіант без префікса DEMO-. */
    private function foreignProducts(): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(DISTINCT product_id) FROM catalog_product_variant WHERE sku NOT LIKE ?',
            [DemoCatalog::SKU_PREFIX.'%'],
        );
    }

    /** @param array<string, int> $report */
    private function table(SymfonyStyle $io, array $report): void
    {
        if ([] !== $report) {
            $io->table(['що', 'скільки'], array_map(static fn (string $k, int $v): array => [$k, $v], array_keys($report), array_values($report)));
        }
    }
}
