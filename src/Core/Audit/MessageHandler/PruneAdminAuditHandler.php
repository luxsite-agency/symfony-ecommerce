<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Audit\MessageHandler;

use App\Core\Audit\AdminAuditLog;
use App\Core\Audit\Message\PruneAdminAudit;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PruneAdminAuditHandler
{
    public function __construct(private readonly AdminAuditLog $log)
    {
    }

    public function __invoke(PruneAdminAudit $message): void
    {
        $this->log->prune();
    }
}
