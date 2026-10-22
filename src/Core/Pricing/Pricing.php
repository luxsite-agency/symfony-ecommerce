<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Pricing;

use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Option\InvalidOptionsException;
use App\Core\Catalog\Option\OptionPriceCalculator;
use App\Core\Customer\Entity\CustomerGroup;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Єдина точка ціни за одиницю: пороги (PriceResolver) → опції → акції (DiscountResolver) → компоненти. Формула —
 * OptionPriceCalculator і ADR «Ціна рядка». Рядок кошика, замовлення, додавання в кошик, форма опцій на картці й
 * dataLayer беруть ціну тут, а не з PriceResolver/OptionPriceCalculator напряму, щоб скрізь було те саме число.
 *
 * $group ніде не має значення за замовчуванням навмисно (консиліум 07.10.2026, п. 10): Site-шар бере групу з
 * CustomerContext і передає явно; публічні канали (фід, schema, sitemap, кеш каталогу) пишуть null явно — це видно на рев'ю.
 */
final class Pricing
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly DiscountResolver $discounts,
        private readonly OptionPriceCalculator $options,
        private readonly ?RequestStack $requests = null,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale = 'uk',
    ) {
    }

    /** Ціна варіанта без опцій (лістинги, картка до вибору опцій, фід). */
    public function quote(ProductVariant $variant, string $quantity, ?CustomerGroup $group): PriceQuote
    {
        // акції бачать групу лише коли спрацював її поріг (ResolvedPrice::pricingGroup); роздрібна ціна — роздрібні акції
        $resolved = $this->prices->resolve($variant, $quantity, $group);

        return $this->discounts->quote($variant, $resolved->price, $resolved->pricingGroup);
    }

    /**
     * Ціна на картці, у мета-описі й schema Offer: мінімальна кількість, опції за замовчуванням, акції — те, що
     * покупець заплатить, якщо нічого не змінить. Обов'язкова група опцій без default (вибір невалідний) — ціна без опцій.
     * $group — група покупця (null — роздріб: фід, schema, SEO-мета, гість).
     */
    public function cardQuote(ProductVariant $variant, ?string $locale, ?CustomerGroup $group): PriceQuote
    {
        try {
            return $this->line($variant, $variant->getMinQuantity(), null, $group, $locale)->quote;
        } catch (InvalidOptionsException) {
            return $this->quote($variant, $variant->getMinQuantity(), $group);
        }
    }

    /**
     * Рядок кошика чи замовлення: збережений вибір перераховується за поточним каталогом. Без снепшота в товару з
     * опціями — значення за замовчуванням (так само в кошику й PlaceOrder; обов'язкова група без default — помилка).
     * Мова підписів у снепшоті — $locale, інакше мова поточного запиту, інакше мова магазину за замовчуванням.
     *
     * @param array<string, mixed>|null $snapshot
     *
     * @throws InvalidOptionsException
     */
    public function line(ProductVariant $variant, string $quantity, ?array $snapshot, ?CustomerGroup $group, ?string $locale = null): LineQuote
    {
        $locale ??= $this->requests?->getCurrentRequest()?->getLocale() ?? $this->defaultLocale;
        $configuration = null !== $snapshot
            ? $this->options->reconfigure($variant, $quantity, $snapshot, $group, $locale)
            : $this->options->configure($variant, $quantity, [], $locale, $group);

        return null !== $configuration ? new LineQuote($configuration->quote, $configuration) : new LineQuote($this->quote($variant, $quantity, $group));
    }

    /**
     * Новий вибір опцій (форма на картці, додавання в кошик): код групи → код значення | список | число.
     *
     * @param array<string, mixed> $input
     *
     * @throws InvalidOptionsException
     */
    public function configure(ProductVariant $variant, string $quantity, array $input, string $locale, ?CustomerGroup $group): LineQuote
    {
        $configuration = $this->options->configure($variant, $quantity, $input, $locale, $group);

        return null !== $configuration ? new LineQuote($configuration->quote, $configuration) : new LineQuote($this->quote($variant, $quantity, $group));
    }
}
