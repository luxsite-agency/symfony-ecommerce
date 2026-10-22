<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Seo;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Pricing\Pricing;
use App\Site\Catalog\ProductCards;
use App\Site\Catalog\ProductPresenter;

/**
 * Offer для schema.org Product (seo-tech-notes п. 4): один активний варіант — Offer, кілька — AggregateOffer
 * (lowPrice/highPrice). «Ціна від» (PriceMode::From: опції, розмір, тираж) — завжди AggregateOffer з lowPrice, навіть для
 * одного варіанта: точна Offer.price не відповідала б ціні в кошику. «Ціна за запитом» — offers нема зовсім: Offer без ціни Google відхиляє, а price 0 показав би 0 грн.
 * Наявність — як на картці: в наявності → InStock, під замовлення → MadeToOrder, нема → OutOfStock.
 * Ціна — Pricing::cardQuote (опції за замовчуванням + акції), та сама, що на картці й у кошику.
 */
final class ProductSchema
{
    public function __construct(private readonly Pricing $pricing)
    {
    }

    private const array AVAILABILITY = [
        ProductCards::AVAILABLE => 'https://schema.org/InStock',
        ProductCards::TO_ORDER => 'https://schema.org/MadeToOrder',
        ProductCards::OUT => 'https://schema.org/OutOfStock',
    ];

    /** @return array<string, mixed>|null */
    public function offers(Product $product, string $url, string $seller, ?string $locale = null): ?array
    {
        if (PriceMode::OnRequest === $product->getPriceMode()) {
            return null;
        }
        /** @var list<ProductVariant> $variants */
        $variants = array_values(array_filter($product->getVariants()->toArray(), static fn (ProductVariant $v): bool => $v->isActive() && $v->getPrice()->isPositive()));
        if ([] === $variants) {
            return null;
        }
        $availability = array_map(ProductPresenter::availability(...), $variants);
        $best = \in_array(ProductCards::AVAILABLE, $availability, true) ? ProductCards::AVAILABLE : (\in_array(ProductCards::TO_ORDER, $availability, true) ? ProductCards::TO_ORDER : ProductCards::OUT);
        $prices = array_map(fn (ProductVariant $v): int => $this->pricing->cardQuote($v, $locale, null)->price->amount, $variants);
        $common = [
            'priceCurrency' => $variants[0]->getCurrency(),
            'availability' => self::AVAILABILITY[$best],
            'url' => $url,
            'seller' => ['@type' => 'Organization', 'name' => $seller],
        ];
        $single = 1 === \count($variants) || min($prices) === max($prices);
        if (PriceMode::From === $product->getPriceMode()) {
            return ['@type' => 'AggregateOffer', 'lowPrice' => self::decimal(min($prices))] + ($single ? [] : ['highPrice' => self::decimal(max($prices))]) + ['offerCount' => \count($variants)] + $common;
        }
        if ($single) {
            return ['@type' => 'Offer', 'price' => self::decimal(min($prices))] + $common;
        }

        return ['@type' => 'AggregateOffer', 'lowPrice' => self::decimal(min($prices)), 'highPrice' => self::decimal(max($prices)), 'offerCount' => \count($variants)] + $common;
    }

    private static function decimal(int $minor): string
    {
        return \sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
