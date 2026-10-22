<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Enum;

/** Облік залишку (ADR Rev 5): списується зі складу, під замовлення з терміном, не ведеться (послуги). */
enum StockPolicy: string
{
    case InStock = 'in_stock';
    case MadeToOrder = 'made_to_order';
    case NotTracked = 'not_tracked';
}
