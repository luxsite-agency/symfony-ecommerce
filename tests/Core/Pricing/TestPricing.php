<?php

declare(strict_types=1);

namespace App\Tests\Core\Pricing;

use App\Core\Catalog\Option\OptionPriceCalculator;
use App\Core\Pricing\DiscountResolver;
use App\Core\Pricing\PriceResolver;
use App\Core\Pricing\Pricing;
use App\Core\Pricing\Promotion\ArrayPromotionProvider;
use App\Core\Pricing\Promotion\Entity\Promotion;

/** Фасад ціни без контейнера для юніт-тестів: пороги + опції + акції з масиву. */
final class TestPricing
{
    /** @param list<Promotion> $promotions */
    public static function create(array $promotions = []): Pricing
    {
        $discounts = new DiscountResolver(new ArrayPromotionProvider($promotions));

        return new Pricing(new PriceResolver(), $discounts, new OptionPriceCalculator(new PriceResolver(), $discounts));
    }

    /** @param list<Promotion> $promotions */
    public static function options(array $promotions = []): OptionPriceCalculator
    {
        return new OptionPriceCalculator(new PriceResolver(), new DiscountResolver(new ArrayPromotionProvider($promotions)));
    }
}
