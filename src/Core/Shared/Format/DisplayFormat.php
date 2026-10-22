<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Format;

use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;
use DateTimeInterface;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use NumberFormatter;

/**
 * Формат для людей — один на вітрину, адмінку й листи (Twig-фільтри money / qty / date_short у FormatExtension).
 * Для збереження, API, фідів і атрибутів форм — Money::toDecimalString() і Quantity::display(), не це.
 *   money: "1 588,00 ₴" (uk), "$1,234.56" (en_US) — Money::format();
 *   quantity: "32", "2,5", "1 250" — без зайвих нулів, до трьох знаків (Quantity::SCALE), роздільники локалі;
 *   date: "18.10.2026", з часом — "18.10.2026 11:07" (uk/ru), "10/18/2026 11:07" (en) — порядок і роздільники дати з ICU під локаль.
 * Нерозривні пробіли між групами розрядів і перед валютою/одиницею — щоб сума й кількість не переносились.
 */
final class DisplayFormat
{
    /** @var array<string, NumberFormatter> */
    private static array $numbers = [];

    /** @var array<string, IntlDateFormatter> */
    private static array $dates = [];

    public static function money(Money $money, string $locale): string
    {
        return $money->format($locale);
    }

    public static function quantity(string|int|float $quantity, string $locale): string
    {
        $value = \is_string($quantity) ? Quantity::display($quantity) : (string) $quantity;
        $formatter = self::$numbers[$locale] ??= self::numberFormatter($locale);

        return $formatter->format(str_contains($value, '.') ? (float) $value : (int) $value) ?: $value;
    }

    public static function date(DateTimeInterface $date, string $locale, bool $withTime = false): string
    {
        $key = $locale.($withTime ? '|t' : '');
        $formatter = self::$dates[$key] ??= self::dateFormatter($locale, $withTime);
        // часовий пояс — як у Twig |date: типовий PHP, а не той, з яким дата прийшла з БД
        $formatter->setTimeZone(date_default_timezone_get());

        return $formatter->format($date) ?: $date->format($withTime ? 'd.m.Y H:i' : 'd.m.Y');
    }

    private static function numberFormatter(string $locale): NumberFormatter
    {
        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, Quantity::SCALE);

        return $formatter;
    }

    private static function dateFormatter(string $locale, bool $withTime): IntlDateFormatter
    {
        // дата й час через пробіл, без коми ICU («18.10.2026, 11:07») — так само, як у списках EasyAdmin (DashboardController::configureCrud)
        $generator = new IntlDatePatternGenerator($locale);
        $pattern = ($generator->getBestPattern('ddMMyyyy') ?: 'dd.MM.yyyy').($withTime ? ' '.($generator->getBestPattern('HHmm') ?: 'HH:mm') : '');

        return new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, IntlDateFormatter::GREGORIAN, $pattern);
    }
}
