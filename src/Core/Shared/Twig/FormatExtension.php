<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Twig;

use App\Core\Catalog\Enum\SalesUnit;
use App\Core\Shared\Format\DisplayFormat;
use App\Core\Shared\Money\Money;
use DateTimeInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Формат чисел для людей у будь-якому шаблоні (вітрина, адмінка, листи). Локаль — з аргументу (листи: order.locale),
 * інакше поточна локаль перекладача (запит). Правила — DisplayFormat.
 *   {{ line.lineTotal|money }}             → 1 588,00 ₴
 *   {{ item.quantity|qty(variant.unit) }}  → 32 шт, 2,5 м; без одиниці — 32
 *   {{ order.placedAt|date_short }}        → 18.10.2026;  |date_short(true) → 18.10.2026, 11:07
 * Порожнє значення (null) — порожній рядок, щоб `{{ x.listPrice|money }}` не падав.
 */
final class FormatExtension extends AbstractExtension
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->money(...)),
            new TwigFilter('qty', $this->quantity(...)),
            new TwigFilter('date_short', $this->date(...)),
        ];
    }

    public function money(?Money $money, ?string $locale = null): string
    {
        return null === $money ? '' : DisplayFormat::money($money, $this->locale($locale));
    }

    /** @param SalesUnit|string|null $unit одиниця продажу варіанта або її код (pc, m, m2, kg, set) */
    public function quantity(string|int|float|null $quantity, SalesUnit|string|null $unit = null, ?string $locale = null): string
    {
        if (null === $quantity || '' === $quantity) {
            return '';
        }
        $locale = $this->locale($locale);
        $text = DisplayFormat::quantity($quantity, $locale);
        $code = $unit instanceof SalesUnit ? $unit->value : $unit;

        return null === $code || '' === $code ? $text : $text."\u{a0}".$this->translator->trans($code, [], 'units', $locale);
    }

    public function date(?DateTimeInterface $date, bool $withTime = false, ?string $locale = null): string
    {
        return null === $date ? '' : DisplayFormat::date($date, $this->locale($locale), $withTime);
    }

    private function locale(?string $locale): string
    {
        return $locale ?? ($this->translator instanceof LocaleAwareInterface ? $this->translator->getLocale() : 'uk');
    }
}
