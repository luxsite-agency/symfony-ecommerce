<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Pricing;

use App\Core\Catalog\Option\OptionConfiguration;
use App\Core\Shared\Money\Money;

/**
 * Ціна одиниці рядка з фасаду Pricing::line()/configure(): quote — до сплати, до акцій (з опціями й компонентами) і
 * акції; configuration — обрані опції (снепшот, компоненти) або null для товару без опцій.
 */
final readonly class LineQuote
{
    public function __construct(public PriceQuote $quote, public ?OptionConfiguration $configuration = null)
    {
    }

    public function unitPrice(): Money
    {
        return $this->quote->price;
    }

    /** Ціна до акцій або null, коли знижки нема (перекреслена ціна, OrderItem::applyDiscount). */
    public function listPrice(): ?Money
    {
        return $this->quote->hasDiscount() ? $this->quote->listPrice : null;
    }
}
