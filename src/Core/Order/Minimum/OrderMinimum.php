<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Minimum;

use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Мінімум замовлення групи покупця (E13 PR-7, консиліум 07.10 п. 3.5): сума рядків у валюті магазину і/або сумарна
 * кількість одиниць головних рядків. Одне правило для кошика, checkout і швидкого замовлення: PlaceOrder перевіряє
 * його в транзакції за актуальною групою, тож швидким замовленням з картки мінімум не обійти. Гість і покупець без
 * групи мінімуму не мають. Інша валюта за сумою не порівнюється (кошик в одній валюті, інакше PlaceOrder відмовить сам).
 */
final class OrderMinimum
{
    public function __construct(#[Autowire('%app.default_currency%')] private readonly string $currency)
    {
    }

    public function shortfall(?CustomerGroup $group, Money $total, string $quantity): ?MinimumShortfall
    {
        if (null === $group) {
            return null;
        }
        $minAmount = $group->getMinOrderAmount($this->currency);
        $missingAmount = null !== $minAmount && $total->currency === $this->currency && $total->lessThan($minAmount) ? $minAmount->subtract($total) : null;
        $minQuantity = $group->getMinOrderQuantity();
        $missingQuantity = null !== $minQuantity && Quantity::compare($quantity, $minQuantity) < 0 ? Quantity::normalize(bcsub($minQuantity, $quantity, 3)) : null;
        if (null === $missingAmount && null === $missingQuantity) {
            return null;
        }

        return new MinimumShortfall(null === $missingAmount ? null : $minAmount, $missingAmount, null === $missingQuantity ? null : $minQuantity, $missingQuantity);
    }
}
