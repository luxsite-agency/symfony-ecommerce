<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Audit\Command;

use App\Core\Audit\AdminAuditLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'admin:audit:prune', description: 'Прибрати журнал змін адмінки, старший за app.admin_audit_months (0 = вимкнено)')]
final class PruneAdminAuditCommand extends Command
{
    public function __construct(private readonly AdminAuditLog $log)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(\sprintf('Видалено рядків журналу: %d', $this->log->prune()));

        return Command::SUCCESS;
    }
}
