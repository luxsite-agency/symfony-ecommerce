<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Demo;

use App\Core\Customer\Auth\AuthTokenPolicy;
use App\Core\Customer\Entity\Customer;

/** Демо-акаунт публічного демо: жодного робочого посилання з листів (скидання пароля, зміна email, експорт) не існує (рев'ю #66 M3). */
final class DemoTokenPolicy implements AuthTokenPolicy
{
    public function __construct(private readonly DemoAccounts $accounts)
    {
    }

    public function issuesTokensFor(Customer $customer): bool
    {
        return !$this->accounts->isDemo($customer);
    }
}
