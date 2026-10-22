<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Pricing;

use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Pricing\Promotion\PromotionProviderInterface;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Застосовує акції до предмета знижки S — ціни одиниці після порогів (PriceResolver) і опцій (модифікатори й число,
 * OptionPriceCalculator), але без компонентів: вони додаються після і під знижку не потрапляють (формула — докблок
 * OptionPriceCalculator і ADR «Ціна рядка»). Викликати через фасад Pricing, не напряму.
 *
 * $group — група, чий поріг дав ціну (ResolvedPrice::pricingGroup з фасаду Pricing), не група покупця: акція без груп
 * діє на роздрібну ціну будь-кого, а на групову — лише з явного дозволу (Promotion::appliesTo); null — роздрібна ціна.
 *
 * Серед чинних для варіанта акцій перемагає найвищий пріоритет, при рівному — більша знижка на S. Сумування лише коли
 * переможець і наступні позначені stackable — вони застосовуються послідовно до вже зниженої ціни. Ціна не падає нижче нуля.
 */
final class DiscountResolver
{
    public function __construct(private readonly PromotionProviderInterface $promotions, private readonly ?ClockInterface $clock = null)
    {
    }

    public function quote(ProductVariant $variant, Money $unitPrice, ?CustomerGroup $group, ?DateTimeImmutable $at = null): PriceQuote
    {
        $at ??= $this->clock?->now() ?? new DateTimeImmutable();
        $applicable = array_values(array_filter($this->promotions->running($at), static fn (Promotion $p): bool => $p->appliesTo($variant, $group) && $p->discountFor($unitPrice)->isPositive()));
        if ([] === $applicable) {
            return PriceQuote::plain($unitPrice);
        }
        usort($applicable, static function (Promotion $a, Promotion $b) use ($unitPrice): int {
            return [$b->getPriority(), $b->discountFor($unitPrice)->amount, $a->getId() ?? 0] <=> [$a->getPriority(), $a->discountFor($unitPrice)->amount, $b->getId() ?? 0];
        });

        $price = $unitPrice;
        $applied = [];
        foreach ($applicable as $promotion) {
            if ([] !== $applied && (!$promotion->isStackable() || !$applicable[0]->isStackable())) {
                continue;
            }
            $price = $price->subtract($promotion->discountFor($price));
            $applied[] = ['id' => $promotion->getId(), 'name' => $promotion->getName()];
            if (!$promotion->isStackable()) {
                break;
            }
        }

        return new PriceQuote($unitPrice, $price, $applied);
    }
}
