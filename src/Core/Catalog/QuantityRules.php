<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog;

use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Shared\Quantity\Quantity;
use InvalidArgumentException;

/**
 * Правило кількості варіанта (ADR Rev 5/7): ≥ minQuantity і (qty − min) кратне quantityStep — 1,5 шт штучного товару
 * не пройде ні в кошик, ні в замовлення (консиліум PR #6 п. 5). Спільне для Cart, CartItem, PlaceOrder і калькулятора.
 */
final class QuantityRules
{
    public static function isAllowed(ProductVariant $variant, string $quantity): bool
    {
        return null === self::problem($variant, $quantity);
    }

    /** null або код проблеми: below_min | invalid_step | invalid (не число). */
    public static function problem(ProductVariant $variant, string $quantity): ?string
    {
        try {
            $quantity = Quantity::positive($quantity);
        } catch (InvalidArgumentException) {
            return 'invalid';
        }
        if (Quantity::compare($quantity, $variant->getMinQuantity()) < 0) {
            return 'below_min';
        }
        $step = $variant->getQuantityStep();
        if (1 === bccomp($step, '0', 3) && 0 !== bccomp(bcmod(bcsub($quantity, $variant->getMinQuantity(), 3), $step, 3), '0', 3)) {
            return 'invalid_step';
        }

        return null;
    }

    /** @throws CatalogInvariantViolation */
    public static function assertAllowed(ProductVariant $variant, string $quantity): string
    {
        $problem = self::problem($variant, $quantity);
        if (null !== $problem) {
            throw new CatalogInvariantViolation(match ($problem) {
                'below_min' => \sprintf('Мінімальна кількість для %s — %s', $variant->getSku(), $variant->getMinQuantity()), 'invalid_step' => \sprintf('Кількість для %s має бути кратна %s (від %s)', $variant->getSku(), $variant->getQuantityStep(), $variant->getMinQuantity()), default => \sprintf('Кількість для %s некоректна', $variant->getSku()),
            });
        }

        return Quantity::positive($quantity);
    }
}
