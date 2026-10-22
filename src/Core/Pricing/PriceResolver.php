<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Pricing;

use App\Core\Catalog\Entity\PriceTier;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;

/**
 * Ціна за одиницю для варіанта й кількості (ADR Rev 7). Пороги PriceTier діляться на загальні (без групи) і групові.
 * Гість і покупець без групи бачать лише загальні пороги. Покупець з групою: якщо у варіанта є хоч один поріг його
 * групи, діють лише пороги групи, загальні для нього не існують (рішення власника 07.10.2026: інакше оптовик за 10 шт.
 * платив би за загальним порогом дорожче, ніж за 9 шт. за груповим); якщо порогів групи у варіанта нема, діють загальні.
 * Серед чинних порогів береться найбільший minQuantity ≤ qty; без порога — базова ціна варіанта.
 * resolve() каже ще й, чи ціна групова (спрацював поріг групи): лише тоді акції отримують групу, інакше ціна роздрібна
 * і акції без груп діють на неї, хоч би покупець і був у групі.
 * Групи порівнюються за id, не за ідентичністю об'єкта: після $em->clear() (прайс-листи, Messenger) група й тир
 * живуть у різних екземплярах. Кошик і замовлення не читають priceMinor напряму. Опції (Rev 9) — поверх, у калькуляторі.
 */
final class PriceResolver
{
    public function unitPrice(ProductVariant $variant, string $quantity, ?CustomerGroup $group = null): Money
    {
        return $this->resolve($variant, $quantity, $group)->price;
    }

    public function resolve(ProductVariant $variant, string $quantity, ?CustomerGroup $group): ResolvedPrice
    {
        $quantity = Quantity::positive($quantity);
        $general = $own = [];
        foreach ($variant->getPriceTiers() as $tier) {
            $tierGroup = $tier->getCustomerGroup();
            if (null === $tierGroup) {
                $general[] = $tier;
            } elseif (null !== $group && $tierGroup->is($group)) {
                $own[] = $tier;
            }
        }

        $best = null;
        foreach ([] !== $own ? $own : $general as $tier) {
            if (Quantity::compare($tier->getMinQuantity(), $quantity) > 0) {
                continue;
            }
            if (null === $best || Quantity::compare($tier->getMinQuantity(), $best->getMinQuantity()) > 0) {
                $best = $tier;
            }
        }

        if (!$best instanceof PriceTier) {
            return new ResolvedPrice($variant->getPrice(), null);
        }

        return new ResolvedPrice($best->getPrice(), null === $best->getCustomerGroup() ? null : $group);
    }
}
