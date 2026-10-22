<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Form\Product;

use App\Core\Shared\Money\Money;

/** Десятковий рядок з форми ⇄ Money без float: «1 250,50» → Money(125050). */
final class MoneyInput
{
    public static function parse(mixed $raw, string $currency): ?Money
    {
        $text = str_replace([' ', "\u{a0}", ','], ['', '', '.'], trim((string) $raw));
        if ('' === $text) {
            return null;
        }

        return Money::fromDecimalString($text, $currency);
    }

    public static function format(?Money $money): ?string
    {
        return $money?->toDecimalString();
    }
}
