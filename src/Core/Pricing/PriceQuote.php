<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Pricing;

use App\Core\Shared\Money\Money;

/** Ціна за одиницю після порогів і акцій: listPrice — до акцій (перекреслена), price — до сплати, promotions — що спрацювало. */
final readonly class PriceQuote
{
    /** @param list<array{id: ?int, name: string}> $promotions */
    public function __construct(public Money $listPrice, public Money $price, public array $promotions = [])
    {
    }

    public static function plain(Money $price): self
    {
        return new self($price, $price);
    }

    public function discount(): Money
    {
        return $this->listPrice->subtract($this->price);
    }

    public function hasDiscount(): bool
    {
        return $this->discount()->isPositive();
    }

    /**
     * Перекреслена ціна на картці, у фідах і schema: ціна до акції, якщо акція діє, інакше ручна стара ціна варіанта —
     * лише коли вона більша за ціну до сплати (з платними опціями за замовчуванням ручна oldPrice бази може бути нижчою).
     */
    public function compareAt(?Money $manualOldPrice): ?Money
    {
        if ($this->hasDiscount()) {
            return $this->listPrice;
        }

        return null !== $manualOldPrice && $manualOldPrice->greaterThan($this->price) ? $manualOldPrice : null;
    }

    /** Округлений відсоток знижки для бейджа («−15 %»). */
    public function discountPercent(): int
    {
        return $this->listPrice->isPositive() ? (int) round(100 * $this->discount()->amount / $this->listPrice->amount) : 0;
    }

    /** Назви акцій через кому — снепшот у рядку замовлення. */
    public function promotionNames(): string
    {
        return implode(', ', array_map(static fn (array $p): string => $p['name'], $this->promotions));
    }
}
