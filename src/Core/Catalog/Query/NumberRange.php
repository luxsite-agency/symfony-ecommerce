<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

/**
 * Діапазон числа: у фільтрі — межі вибору (будь-яка може бути null), у фасеті — MIN/MAX наявних значень.
 * Значення рядками DECIMAL(16,4), як їх повертає MySQL: без float. Нечислове (з URL) → null, кома → крапка, min > max — міняються місцями.
 */
final readonly class NumberRange
{
    public ?string $min;
    public ?string $max;

    public function __construct(?string $min, ?string $max)
    {
        $min = self::normalize($min);
        $max = self::normalize($max);
        // «від 5600 до 250» з URL — переставляємо межі замість порожнього результату (UX-аудит S11)
        if (null !== $min && null !== $max && (float) $min > (float) $max) {
            [$min, $max] = [$max, $min];
        }
        $this->min = $min;
        $this->max = $max;
    }

    private static function normalize(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) && abs((float) $value) < 1e12 ? $value : null;
    }

    public function isEmpty(): bool
    {
        return null === $this->min && null === $this->max;
    }
}
