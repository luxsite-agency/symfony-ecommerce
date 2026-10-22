<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Feed\Command;

use App\Site\Feed\MerchantFeeds;
use App\Site\Feed\Message\GenerateMerchantFeeds;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Згенерувати товарні фіди (Google Merchant і Meta) у public/feeds/ (усі мови або --locale); 500k карток — хвилини,
 * пам'ять стала. --async лише ставить генерацію в чергу feeds (feed-worker) — так робить bin/store restore.
 */
#[AsCommand(name: 'feed:merchant:generate', description: 'Згенерувати товарні фіди Google Merchant і Meta (RSS 2.0, g:) у public/feeds/ кожною мовою')]
final class MerchantFeedCommand
{
    public function __construct(private readonly MerchantFeeds $feeds, private readonly MessageBusInterface $bus)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Лише ці мови, через кому')] ?string $locale = null,
        #[Option(description: 'Товарів (за діапазоном id) в одному пакеті')] int $batch = 500,
        #[Option(description: 'Не генерувати тут, а поставити в чергу feeds (усі мови)')] bool $async = false,
    ): int {
        if ($async) {
            $this->bus->dispatch(new GenerateMerchantFeeds());
            $io->success('Генерацію фідів поставлено в чергу feeds');

            return Command::SUCCESS;
        }
        $locales = null === $locale ? null : array_values(array_filter(array_map(trim(...), explode(',', $locale))));
        $results = $this->feeds->generate($locales, max(1, $batch), static function (string $l, int $done, int $max) use ($io): void {
            if ($io->isVerbose()) {
                $io->writeln(\sprintf('  %s: id %d з %d, пам\'ять %.0f МБ', $l, $done, $max, memory_get_usage(true) / 1048576));
            }
        });
        if (null === $results) {
            $io->warning('Фід уже генерується іншим процесом (lock)');

            return Command::FAILURE;
        }
        $rows = [];
        foreach ($results as $r) {
            foreach ($r['paths'] as $channel => $path) {
                $rows[] = [$r['locale'], $channel, $r['products'], $r['items'], $r['seconds'], $path];
            }
        }
        $io->table(['мова', 'канал', 'товарів', 'item', 'с', 'файл'], $rows);
        $io->success(\sprintf('Готово; пікова пам\'ять %.0f МБ', memory_get_peak_usage(true) / 1048576));

        return Command::SUCCESS;
    }
}
