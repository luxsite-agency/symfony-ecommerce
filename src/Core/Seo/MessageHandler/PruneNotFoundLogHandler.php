<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo\MessageHandler;

use App\Core\Seo\Message\PruneNotFoundLog;
use App\Core\Seo\NotFoundLog;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PruneNotFoundLogHandler
{
    public function __construct(private readonly NotFoundLog $log)
    {
    }

    public function __invoke(PruneNotFoundLog $message): void
    {
        $this->log->prune();
    }
}
