<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Translation;

/**
 * Мова, якою сутності з перекладами показують себе рядком (__toString: селекти й колонки адмінки, хлібні крихти).
 * Сутність не має доступу до запиту, тож мову ставить DisplayLocaleListener на початку кожного запиту (локаль сайту або
 * адмінки), а між запитами (воркери) скидає; без запиту (консоль) — мова магазину за замовчуванням. До аудиту UX A7 __toString
 * брав перший завантажений переклад, і в uk-адмінці категорії виходили російською.
 */
final class DisplayLocale
{
    private static ?string $locale = null;
    private static ?string $default = null;

    public static function set(?string $locale, ?string $default = null): void
    {
        self::$locale = $locale;
        self::$default = $default ?? self::$default;
    }

    /** @return list<string> мови в порядку спроби: поточна, за замовчуванням (без повторів, без порожніх) */
    public static function preferred(): array
    {
        return array_values(array_unique(array_filter([self::$locale, self::$default], static fn (?string $l): bool => null !== $l && '' !== $l)));
    }
}
