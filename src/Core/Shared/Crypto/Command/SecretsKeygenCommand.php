<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Crypto\Command;

use App\Core\Shared\Crypto\SecretBox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Новий ключ шифрування секретів налаштувань: вставити в .env.local як SETTINGS_SECRET_KEY (600, не в git/чат/доки). */
#[AsCommand(name: 'app:secrets:keygen', description: 'Згенерувати SETTINGS_SECRET_KEY для .env.local')]
final class SecretsKeygenCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('SETTINGS_SECRET_KEY='.SecretBox::generateKey());

        return Command::SUCCESS;
    }
}
