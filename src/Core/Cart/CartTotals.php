<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Cart;

use App\Core\Pricing\PriceFingerprint;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;

/** Підсумок кошика: рядки й сума товарів. Доставка/знижки — поверх, у checkout (Ф3: DiscountResolver, тарифи). */
final readonly class CartTotals
{
    /** @param list<CartLine> $lines */
    public function __construct(public array $lines, public Money $itemsTotal)
    {
    }

    public function count(): int
    {
        return \count($this->lines);
    }

    /** Відбиток цін для форми checkout: PlaceOrder звіряє з ним перерахунок у транзакції. */
    public function priceFingerprint(): string
    {
        return PriceFingerprint::of(array_map(static fn (CartLine $line): array => ['sku' => $line->item->getVariant()->getSku(), 'quantity' => $line->item->getQuantity(), 'unit' => $line->unitPrice], $this->lines));
    }

    /** Сумарна кількість одиниць головних рядків (мінімум групи, E13 PR-7). */
    public function totalQuantity(): string
    {
        $quantity = '0';
        foreach ($this->lines as $line) {
            $quantity = bcadd($quantity, $line->item->getQuantity(), 3);
        }

        return Quantity::normalize($quantity);
    }

    public function hasProblems(): bool
    {
        foreach ($this->lines as $line) {
            if (null !== $line->problem) {
                return true;
            }
        }

        return false;
    }
}
