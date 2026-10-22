<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo\Command;

use App\Core\Seo\NotFoundLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'seo:not-found:prune', description: 'Прибрати журнал 404: старіші за app.seo_not_found_days і понад app.seo_not_found_limit рядків (--all — усе)')]
final class PruneNotFoundCommand
{
    public function __construct(private readonly NotFoundLog $log)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Очистити журнал повністю')] bool $all = false): int
    {
        $io->success(\sprintf('Видалено рядків журналу 404: %d', $all ? $this->log->clear() : $this->log->prune()));

        return Command::SUCCESS;
    }
}
