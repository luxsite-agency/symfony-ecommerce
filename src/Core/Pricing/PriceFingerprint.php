<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Pricing;

use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;

/**
 * Відбиток цін, які бачив покупець: sha256 по рядках (SKU, кількість, ціна одиниці з валютою) у порядку кошика.
 * Checkout рендерить його з CartTotals, PlaceOrder рахує з тих самих Pricing::line у транзакції; розбіжність (акція
 * закінчилась, ціна змінилась в адмінці, опції перераховано, кошик змінено в іншій вкладці) — замовлення не створюється,
 * покупець підтверджує нову суму (рішення власника 08.10.2026). Підробка відбитка нічого не дає: в замовлення йде
 * серверна ціна, відбиток лише вирішує, чи питати ще раз.
 */
final class PriceFingerprint
{
    /** @param iterable<array{sku: string, quantity: string, unit: Money}> $lines */
    public static function of(iterable $lines): string
    {
        $parts = [];
        foreach ($lines as $line) {
            $parts[] = implode('|', [$line['sku'], Quantity::normalize($line['quantity']), $line['unit']->amount, $line['unit']->currency]);
        }

        return hash('sha256', implode("\n", $parts));
    }
}
