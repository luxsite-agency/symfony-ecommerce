<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\License\Command;

use App\Core\License\ThirdPartyComponents;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Перелік сторонніх компонентів (runtime-пакети composer.lock: назва, версія, ліцензія) у файл app.license_components_file
 * для сторінки ліцензії сайту й адмінки. Запускається у збірці образу (Dockerfile.prod), щоб lock не парсився на запитах;
 * без файлу рушій порахує перелік сам при першому запиті. Пакети лише з copyleft-ліцензією (GPL/LGPL/AGPL) — попередження,
 * з --fail-on-copyleft — провал (рушій під Apache 2.0, таких runtime-залежностей бути не має).
 */
#[AsCommand(name: 'license:components', description: 'Перелік сторонніх компонентів з composer.lock у файл для сторінки ліцензії')]
final class LicenseComponentsCommand
{
    public function __construct(private readonly ThirdPartyComponents $components)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Завершитись помилкою, якщо серед runtime-пакетів є GPL/LGPL/AGPL')] bool $failOnCopyleft = false): int
    {
        $components = $this->components->fromComposerLock();
        $this->components->write($components);
        $io->writeln(\sprintf('license:components: %d runtime-пакетів → %s', \count($components), $this->components->file()));
        $copyleft = ThirdPartyComponents::copyleft($components);
        if ([] === $copyleft) {
            $io->success('Copyleft-ліцензій (GPL/LGPL/AGPL) серед runtime-пакетів нема');

            return Command::SUCCESS;
        }
        $io->warning('Runtime-пакети з copyleft-ліцензією або без ліцензії:');
        $io->table(['Пакет', 'Версія', 'Ліцензія'], array_map(array_values(...), $copyleft));

        return $failOnCopyleft ? Command::FAILURE : Command::SUCCESS;
    }
}
