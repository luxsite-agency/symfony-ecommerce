<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shipping\NovaPoshta\MessageHandler;

use App\Core\Shipping\NovaPoshta\Message\SyncNovaPoshtaDirectory;
use App\Core\Shipping\NovaPoshta\NovaPoshtaClient;
use App\Core\Shipping\NovaPoshta\NovaPoshtaDirectorySync;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Без ключа — тихо виходить; паралельний запуск — пропускає; помилка API — виняток → retry Messenger → failed. */
#[AsMessageHandler]
final class SyncNovaPoshtaDirectoryHandler
{
    public function __construct(
        private readonly NovaPoshtaClient $client,
        private readonly NovaPoshtaDirectorySync $sync,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncNovaPoshtaDirectory $message): void
    {
        if (!$this->client->isEnabled()) {
            $this->logger->info('Нова пошта: ключ не задано, синхронізацію довідників пропущено');

            return;
        }
        try {
            $report = $this->sync->run();
        } catch (LockConflictedException) {
            $this->logger->warning('Нова пошта: синхронізація вже виконується, пропуск');

            return;
        }
        $this->logger->info('Нова пошта: довідники оновлено', ['cities' => $report->cities, 'warehouses' => $report->warehouses, 'removed_cities' => $report->removedCities, 'removed_warehouses' => $report->removedWarehouses, 'seconds' => $report->seconds]);
    }
}
