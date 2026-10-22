<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Demo\MessageHandler;

use App\Core\Demo\DemoAccounts;
use App\Core\Demo\DemoCustomers;
use App\Core\Demo\Message\ResetDemoCustomers;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Нічне скидання демо-покупців: усе, що відвідувачі демо наробили в цих акаунтах (замовлення, кошик, обране), зникає,
 * і акаунти знову як після seed. Без app.demo.customer_password (звичайний магазин) — тихий вихід.
 */
#[AsMessageHandler]
final class ResetDemoCustomersHandler
{
    public function __construct(private readonly DemoAccounts $accounts, private readonly DemoCustomers $customers, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ResetDemoCustomers $message): void
    {
        if (!$this->accounts->isEnabled()) {
            return;
        }
        $purged = $this->customers->purge();
        $seeded = $this->customers->seed();
        $this->logger->info('demo.customers_reset', ['purged' => $purged, 'seeded' => $seeded]);
    }
}
