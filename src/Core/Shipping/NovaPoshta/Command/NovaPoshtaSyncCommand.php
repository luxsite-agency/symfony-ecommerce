<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shipping\NovaPoshta\Command;

use App\Core\Shipping\NovaPoshta\Message\SyncNovaPoshtaDirectory;
use App\Core\Shipping\NovaPoshta\NovaPoshtaClient;
use App\Core\Shipping\NovaPoshta\NovaPoshtaDirectorySync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'shipping:np:sync', description: 'Оновити локальні довідники Нової пошти (міста, відділення, поштомати)')]
final class NovaPoshtaSyncCommand
{
    public function __construct(
        private readonly NovaPoshtaClient $client,
        private readonly NovaPoshtaDirectorySync $sync,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Поставити в чергу Messenger замість виконання тут')] bool $async = false): int
    {
        if (!$this->client->isEnabled()) {
            $io->warning('NOVA_POSHTA_API_KEY не задано — довідники Нової пошти вимкнено, на checkout текстове поле');

            return Command::SUCCESS;
        }
        if ($async) {
            $this->bus->dispatch(new SyncNovaPoshtaDirectory());
            $io->success('Синхронізацію поставлено в чергу (async)');

            return Command::SUCCESS;
        }
        try {
            $report = $this->sync->run();
        } catch (LockConflictedException) {
            $io->warning('Синхронізація вже виконується — вихід');

            return Command::SUCCESS;
        }
        $io->success(\sprintf('Міст: %d (прибрано %d), відділень: %d (прибрано %d), %.1f с', $report->cities, $report->removedCities, $report->warehouses, $report->removedWarehouses, $report->seconds));

        return Command::SUCCESS;
    }
}
