<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Twig;

use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Pricing\Pricing;
use App\Core\Shared\Money\Money;
use App\Site\Customer\CustomerContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * site_card_price(variant) — ціна картки з фасаду (мінімальна кількість, опції за замовчуванням, акції): підписи
 * варіантів у select і ціна, яку форма швидкого замовлення передає на звірку в PlaceOrder. Група — з CustomerContext:
 * оптовик бачить і передає на звірку свою ціну, PlaceOrder рахує її за тією ж групою з customerId.
 */
final class PricingExtension
{
    public function __construct(private readonly Pricing $pricing, private readonly RequestStack $requests, private readonly CustomerContext $customers)
    {
    }

    #[AsTwigFunction('site_card_price')]
    public function cardPrice(ProductVariant $variant): Money
    {
        return $this->pricing->cardQuote($variant, $this->requests->getCurrentRequest()?->getLocale(), $this->customers->group())->price;
    }
}
