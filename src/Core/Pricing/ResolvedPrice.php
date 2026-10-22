<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Pricing;

use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Shared\Money\Money;

/**
 * Результат PriceResolver: ціна за одиницю і група, чий поріг спрацював (null — ціна роздрібна: базова або загальний
 * поріг, хоч би покупець і був у групі). Саме pricingGroup, а не група покупця, іде в DiscountResolver: акція без
 * груп діє на роздрібну ціну будь-якого покупця і не лягає поверх оптової (рішення власника 07.10.2026).
 */
final readonly class ResolvedPrice
{
    public function __construct(public Money $price, public ?CustomerGroup $pricingGroup)
    {
    }

    public function isGroupPrice(): bool
    {
        return null !== $this->pricingGroup;
    }
}
