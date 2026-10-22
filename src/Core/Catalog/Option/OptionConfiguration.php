<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Option;

use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Pricing\PriceQuote;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;

/**
 * Розрахована конфігурація рядка (OptionPriceCalculator): ціна одиниці рядка, снепшот (ADR Rev 9 Q2) і компоненти.
 *
 * quote — ціна одиниці рядка за формулою консиліуму 07.10.2026 (див. OptionPriceCalculator): price = акційна S +
 * компоненти (те, що платить покупець), listPrice = S + компоненти (перекреслена, коли є знижка), promotions. Компоненти
 * окремо лежать у снепшоті й стають дочірніми OrderItem з ціною 0 лише для резерву SKU.
 */
final readonly class OptionConfiguration
{
    /**
     * @param array<string, mixed>                                                                              $snapshot
     * @param list<array{variant: ProductVariant, quantity: string, price: Money, group: string, code: string}> $components quantity і price — на одиницю головного товару
     */
    public function __construct(
        public PriceQuote $quote,
        public array $snapshot,
        public array $components,
        public string $key,
    ) {
    }

    /** Кількість компонента на рядок: кількість на одиницю × кількість рядка (DECIMAL(12,3), half-up). */
    public static function componentQuantity(string $perUnit, string $lineQuantity): string
    {
        return Quantity::normalize(bcadd(bcmul($perUnit, $lineQuantity, 7), '0.0005', 3));
    }
}
