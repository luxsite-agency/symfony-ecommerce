<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Quantity;

use App\Core\Shared\Translation\TranslatableInvalidArgumentException;

/**
 * Кількість як DECIMAL(12,3) у рядку (ADR Rev 5: дробова кількість + одиниця виміру). Жодних float:
 * Doctrine віддає decimal рядком, порівняння — через bcmath.
 */
final class Quantity
{
    public const int SCALE = 3;

    /** "2,5" → "2.500"; не більше 9 цифр до коми і 3 після. */
    public static function normalize(string $value): string
    {
        $normalized = str_replace(',', '.', trim($value));
        if (!preg_match('/^-?\d{1,9}(\.\d{1,3})?$/', $normalized)) {
            throw new TranslatableInvalidArgumentException(\sprintf('Не кількість DECIMAL(12,3): "%s"', $value), 'error.quantity.invalid', ['value' => $value]);
        }
        $normalized = bcadd($normalized, '0', self::SCALE);

        return '-0.000' === $normalized ? '0.000' : $normalized;
    }

    public static function positive(string $value): string
    {
        $normalized = self::normalize($value);
        if (bccomp($normalized, '0', self::SCALE) <= 0) {
            throw new TranslatableInvalidArgumentException(\sprintf('Кількість має бути більшою за нуль: "%s"', $value), 'error.quantity.not_positive', ['value' => $value]);
        }

        return $normalized;
    }

    public static function nonNegative(string $value): string
    {
        $normalized = self::normalize($value);
        if (bccomp($normalized, '0', self::SCALE) < 0) {
            throw new TranslatableInvalidArgumentException(\sprintf('Кількість не може бути від\'ємною: "%s"', $value), 'error.quantity.negative', ['value' => $value]);
        }

        return $normalized;
    }

    /** Для показу: "5.000" → "5", "2.500" → "2.5". */
    public static function display(string $value): string
    {
        $normalized = self::normalize($value);

        return str_contains($normalized, '.') ? rtrim(rtrim($normalized, '0'), '.') : $normalized;
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE);
    }
}
