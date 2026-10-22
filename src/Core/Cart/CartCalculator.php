<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Cart;

use App\Core\Cart\Entity\Cart;
use App\Core\Cart\Entity\CartItem;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Catalog\Option\InvalidOptionsException;
use App\Core\Catalog\Option\OptionConfiguration;
use App\Core\Catalog\QuantityRules;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\LineQuote;
use App\Core\Pricing\Pricing;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;

/**
 * Рахує кошик щоразу (ADR D4: ціни в рядках не зберігаються): ціна за одиницю через Pricing::line (пороги → опції за
 * збереженим вибором → акції → компоненти) × кількість, сума рядків. Проблеми (недоступний товар, нема залишку, менше
 * мінімальної кількості, опції більше не валідні) — у рядку, щоб кошик і checkout показали їх, а PlaceOrder не пропустив.
 * Залишок перевіряється сумарно по кошику: той самий варіант може бути в кількох рядках з різними опціями, а компонент —
 * у різних товарах (інакше кошик «ок», а резерв на checkout падає).
 * $group — група покупця з CustomerContext (null — роздріб), та сама, що потім отримає PlaceOrder через customerId;
 * для групи рядок несе ще й роздрібну ціну («роздріб» поруч), якщо вона інша.
 */
final class CartCalculator
{
    public function __construct(private readonly Pricing $pricing)
    {
    }

    public function calculate(Cart $cart, ?CustomerGroup $group): CartTotals
    {
        $quotes = $problems = [];
        $demand = []; // variantId → [variant, Σ кількість] по головних варіантах і компонентах
        foreach ($cart->getItems() as $i => $item) {
            $variant = $item->getVariant();
            $problems[$i] = null;
            try {
                // без снепшота в товару з опціями — значення за замовчуванням, як у PlaceOrder
                $quotes[$i] = $this->pricing->line($variant, $item->getQuantity(), $item->getOptionsSnapshot(), $group);
            } catch (InvalidOptionsException) {
                $quotes[$i] = new LineQuote($this->pricing->quote($variant, $item->getQuantity(), $group));
                $problems[$i] = 'invalid_options'; // значення прибрали / межі змінились / обов'язкова група без вибору — обрати знову
            }
            self::need($demand, $variant, $item->getQuantity());
            foreach ($quotes[$i]->configuration->components ?? [] as $component) {
                self::need($demand, $component['variant'], OptionConfiguration::componentQuantity($component['quantity'], $item->getQuantity()));
            }
        }

        $lines = [];
        $total = Money::zero($cart->getCurrency());
        foreach ($cart->getItems() as $i => $item) {
            $variant = $item->getVariant();
            $quote = $quotes[$i];
            $unit = $quote->unitPrice();
            $lineTotal = $unit->multiplyByDecimal($item->getQuantity());
            $problem = $problems[$i];
            if (!$variant->isOrderable()) {
                $problem = 'unavailable';
            } elseif (!self::inStock($demand, $variant) || !self::componentsInStock($demand, $quote->configuration)) {
                $problem = 'out_of_stock';
            } elseif (null === $problem) {
                // below_min | invalid_step — правила варіанта могли змінитись після додавання в кошик
                $problem = QuantityRules::problem($variant, $item->getQuantity());
            }
            $lines[] = new CartLine($item, $unit, $lineTotal, $problem, $quote->listPrice(), $quote->quote->promotions, $quote->configuration->snapshot ?? $item->getOptionsSnapshot(), $this->retail($item, $quote, $group));
            $total = $total->add($lineTotal);
        }

        return new CartTotals($lines, $total);
    }

    /** Роздрібна ціна одиниці для рядка покупця з групою, якщо вона відрізняється від його ціни; інакше null. */
    private function retail(CartItem $item, LineQuote $quote, ?CustomerGroup $group): ?Money
    {
        if (null === $group) {
            return null;
        }
        try {
            $retail = $this->pricing->line($item->getVariant(), $item->getQuantity(), $quote->configuration->snapshot ?? $item->getOptionsSnapshot(), null)->unitPrice();
        } catch (InvalidOptionsException) {
            $retail = $this->pricing->quote($item->getVariant(), $item->getQuantity(), null)->price;
        }

        return $retail->equals($quote->unitPrice()) ? null : $retail;
    }

    /** @param array<int|string, array{ProductVariant, string}> $demand */
    private static function need(array &$demand, ProductVariant $variant, string $quantity): void
    {
        $key = $variant->getId() ?? spl_object_id($variant);
        $demand[$key] = [$variant, Quantity::normalize(bcadd($demand[$key][1] ?? '0', $quantity, 3))];
    }

    /** @param array<int|string, array{ProductVariant, string}> $demand */
    private static function inStock(array $demand, ProductVariant $variant): bool
    {
        if (StockPolicy::InStock !== $variant->getStockPolicy()) {
            return true;
        }

        return Quantity::compare($variant->getStock(), $demand[$variant->getId() ?? spl_object_id($variant)][1]) >= 0;
    }

    /**
     * Компоненти опцій рядка є в потрібній кількості з урахуванням усього кошика.
     *
     * @param array<int|string, array{ProductVariant, string}> $demand
     */
    private static function componentsInStock(array $demand, ?OptionConfiguration $configuration): bool
    {
        foreach ($configuration->components ?? [] as $component) {
            if (!self::inStock($demand, $component['variant'])) {
                return false;
            }
        }

        return true;
    }
}
