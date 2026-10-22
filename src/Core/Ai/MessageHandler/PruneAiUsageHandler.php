<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Ai\MessageHandler;

use App\Core\Ai\AiUsageStats;
use App\Core\Ai\Message\PruneAiUsage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PruneAiUsageHandler
{
    public function __construct(
        private readonly AiUsageStats $stats,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.ai_usage_months%')] private readonly int $months = 24,
    ) {
    }

    public function __invoke(PruneAiUsage $message): void
    {
        $deleted = $this->stats->prune($this->months);
        if ($deleted > 0) {
            $this->logger->info('ai_usage: прибрано {count} рядків старших за {months} міс.', ['count' => $deleted, 'months' => $this->months]);
        }
    }
}
