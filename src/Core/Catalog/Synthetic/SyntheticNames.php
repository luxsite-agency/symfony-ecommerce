<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Synthetic;

/**
 * Назви й слаги синтетичного каталогу для будь-якої мови з app.locales: відомі мови мають свої слова, решта —
 * англійські. Слаг мови за замовчуванням без префікса, інших — з префіксом мови (слаги унікальні в межах мови).
 */
final class SyntheticNames
{
    private const array WORDS = [
        'uk' => ['Розділ', 'Група', 'Підгрупа', 'Синтетичний товар', 'Характеристика', 'Значення', 'Розмір'],
        'ru' => ['Раздел', 'Группа', 'Подгруппа', 'Синтетический товар', 'Характеристика', 'Значение', 'Размер'],
        'en' => ['Section', 'Group', 'Subgroup', 'Synthetic product', 'Attribute', 'Value', 'Size'],
    ];
    private const array CATEGORY_SLUGS = ['rozdil', 'grupa', 'pidgrupa'];

    public function __construct(private readonly string $defaultLocale)
    {
    }

    public function category(SyntheticCategory $category, string $locale): string
    {
        return self::word($locale, min(2, $category->depth)).' '.$category->label;
    }

    public function categorySlug(SyntheticCategory $category, string $locale): string
    {
        return $this->prefix($locale).self::CATEGORY_SLUGS[min(2, $category->depth)].'-'.$category->label;
    }

    public function product(int $number, string $locale): string
    {
        return self::word($locale, 3).' '.$number;
    }

    public function productSlug(int $number, string $locale): string
    {
        return $this->prefix($locale).'tovar-'.$number;
    }

    public function attribute(SyntheticAttribute $attribute, string $locale): string
    {
        return $attribute->axis ? self::word($locale, 6) : self::word($locale, 4).' '.$attribute->index;
    }

    public function value(SyntheticAttribute $attribute, int $valueIndex, string $locale): string
    {
        return $attribute->axis ? (string) (35 + $valueIndex) : self::word($locale, 5).' '.$attribute->index.'.'.$valueIndex;
    }

    public static function brand(int $index): string
    {
        return 'Brand '.$index;
    }

    public static function brandSlug(int $index): string
    {
        return 'brand-'.$index;
    }

    public static function sku(int $product, int $variant): string
    {
        return \sprintf('SYN-%07d-%d', $product, $variant);
    }

    public static function externalId(int $product, ?int $variant = null): string
    {
        return null === $variant ? 'syn-'.$product : \sprintf('syn-%d-%d', $product, $variant);
    }

    private function prefix(string $locale): string
    {
        return $locale === $this->defaultLocale ? '' : $locale.'-';
    }

    private static function word(string $locale, int $i): string
    {
        return (self::WORDS[$locale] ?? self::WORDS['en'])[$i];
    }
}
