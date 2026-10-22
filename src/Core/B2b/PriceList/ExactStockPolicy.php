<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\PriceList;

use App\Core\Customer\Entity\CustomerGroup;

/**
 * Чи бачить група точні залишки (E13 PR-7 «мінімальна сума/кількість для групи; точні залишки й терміни для оптовика»).
 * Шов для PR-7: поки прапорця на групі нема, прайс-лист усім групам пише наявність словом («є», «під замовлення»,
 * «немає»). PR-7 замінює тіло на читання прапорця групи, підпис лишається.
 */
final class ExactStockPolicy
{
    public function appliesTo(CustomerGroup $group): bool
    {
        return false;
    }
}
