<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Crm;

/**
 * Зріз замовлення для pushOrder (контракт KeyCRM §1): sku + кількість + ціна сайту, покупець, доставка, оплата.
 * Сутність Order з'явиться у Ф2 і збиратиме цей DTO сама; конектор про Order не знає.
 */
final readonly class OrderExport
{
    /**
     * @param list<array{sku: string, quantity: string, unitPriceMinor: int, lineTotalMinor: int}> $items
     * @param array<string, string|null>                                                           $buyer    name, phone, email
     * @param array<string, string|null>                                                           $shipping method, city, address, np_city_ref, np_warehouse_ref, np_warehouse_number
     * @param array<string, string|null>                                                           $payment  method, status
     */
    public function __construct(
        public string $siteOrderNumber,
        public string $currency,
        public array $items,
        public array $buyer = [],
        public array $shipping = [],
        public array $payment = [],
        public ?string $comment = null,
        // підсумки з Order (рядки, доставка, разом) — конектор не перераховує суму з truncation (консиліум PR #6 п. 8)
        public int $itemsTotalMinor = 0,
        public int $shippingMinor = 0,
        public int $totalMinor = 0,
    ) {
    }
}
