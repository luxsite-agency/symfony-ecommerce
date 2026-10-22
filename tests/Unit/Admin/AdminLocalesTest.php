<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\I18n\AdminLocales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Мови інтерфейсу адмінки: типова — мова магазину, якщо адмінка нею перекладена, інакше англійська. */
final class AdminLocalesTest extends TestCase
{
    /** @return iterable<string, array{list<string>, string, string}> мови адмінки, мова магазину → типова мова адмінки */
    public static function defaults(): iterable
    {
        yield 'магазин uk' => [['uk', 'en', 'ru'], 'uk', 'uk'];
        yield 'магазин en (en-pharmacy)' => [['uk', 'en', 'ru'], 'en', 'en'];
        yield 'магазин ru' => [['uk', 'en', 'ru'], 'ru', 'ru'];
        yield 'магазин pl — адмінка ним не перекладена' => [['uk', 'en', 'ru'], 'pl', 'en'];
        yield 'порожній рядок' => [['uk', 'en', 'ru'], '', 'en'];
        yield 'без en у переліку — перша мова' => [['uk', 'ru'], 'pl', 'uk'];
        yield 'порожній перелік — en' => [[], 'pl', 'en'];
    }

    /** @param list<string> $locales */
    #[DataProvider('defaults')]
    public function testDefault(array $locales, string $store, string $expected): void
    {
        self::assertSame($expected, (new AdminLocales($locales, $store))->default());
    }

    public function testSupportsOnlyListedStrings(): void
    {
        $locales = new AdminLocales(['uk', 'en', 'ru'], 'uk');

        foreach (['uk', 'en', 'ru'] as $locale) {
            self::assertTrue($locales->supports($locale), $locale);
        }
        foreach (['pl', 'EN', 'en_AU', '', ' en', null, 1, ['en'], true] as $locale) {
            self::assertFalse($locales->supports($locale), var_export($locale, true));
        }
    }

    public function testAllKeepsConfiguredOrder(): void
    {
        self::assertSame(['uk', 'en', 'ru'], (new AdminLocales(['uk', 'en', 'ru'], 'pl'))->all());
        self::assertSame([], (new AdminLocales([], 'uk'))->all());
    }

    public function testFallbackConstantIsEnglish(): void
    {
        self::assertSame('en', AdminLocales::FALLBACK);
    }

    public function testSwitcherLabelsAreLanguageCodesWithoutUk(): void
    {
        self::assertSame('UA', AdminLocales::label('uk'), 'uk → UA, не UK');
        self::assertSame('EN', AdminLocales::label('en'));
        self::assertSame('RU', AdminLocales::label('ru'));
    }
}
