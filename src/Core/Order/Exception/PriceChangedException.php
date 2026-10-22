<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Exception;

use App\Core\Shared\Format\DisplayFormat;
use App\Core\Shared\Money\Money;

/**
 * Ціна на момент оформлення не та, яку бачив покупець: замовлення не створено. total — нова сума товарів;
 * fingerprint — новий відбиток для повторного підтвердження. Наслідує OrderValidationException, тож будь-який
 * виклик PlaceOrder без окремої обробки покаже це як звичайну помилку форми, а не створить замовлення.
 */
final class PriceChangedException extends OrderValidationException
{
    public function __construct(public readonly Money $total, public readonly string $fingerprint)
    {
        parent::__construct(['price' => \sprintf('Ціна змінилась, нова сума %s. Підтвердіть замовлення ще раз', DisplayFormat::money($total, 'uk'))]);
    }
}
