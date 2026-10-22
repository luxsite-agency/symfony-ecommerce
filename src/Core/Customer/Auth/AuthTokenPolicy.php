<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Auth;

use App\Core\Customer\Entity\Customer;

/**
 * Чи має покупець отримувати робочі одноразові токени з листів (скидання пароля, зміна email, експорт ПД).
 * Шар автентифікації не знає про демо-режим: правило живе в тому модулі, що його потребує (Core\Demo).
 */
interface AuthTokenPolicy
{
    public function issuesTokensFor(Customer $customer): bool;
}
