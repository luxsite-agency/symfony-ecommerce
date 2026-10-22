<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Search;

use App\Admin\Search\AdminSearchQuery;
use App\Admin\Search\KeyboardLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Слова запиту адмінки (телефон з пробілами — одне слово) і друга спроба в іншій розкладці. */
final class AdminSearchQueryTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function terms(): iterable
    {
        yield 'регістр і пробіли' => ['  Aspirin   500 ', ['aspirin', '500']];
        yield 'телефон з пробілами' => ['050 111 22 33', ['0501112233']];
        yield 'телефон з дужками й дефісами' => ['(050) 111-22-33', ['0501112233']];
        yield 'міжнародний' => ['+61 412 345 678', ['+61412345678']];
        yield 'ім’я і телефон' => ['olena 050 111 22 33', ['olena', '0501112233']];
        yield 'номер замовлення лишається' => ['2610-000112', ['2610-000112']];
        yield 'SKU з дефісом' => ['KSH-180 bosch', ['ksh-180', 'bosch']];
        yield 'короткі числа не склеюються' => ['aspirin 500 mg', ['aspirin', '500', 'mg']];
        yield 'повтори' => ['bosch Bosch', ['bosch']];
        yield 'закороткий' => ['a', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('terms')]
    public function testTerms(string $query, array $expected): void
    {
        self::assertSame($expected, AdminSearchQuery::terms($query));
    }

    public function testDigits(): void
    {
        self::assertSame('0501112233', AdminSearchQuery::digits('(050) 111-22-33'));
        self::assertSame('112', AdminSearchQuery::digits('112'));
        self::assertNull(AdminSearchQuery::digits('olena'));
        self::assertNull(AdminSearchQuery::digits('ksh-180'));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function layouts(): iterable
    {
        yield 'укр. слово на англ. розкладці' => ['ghjlernb', ['продукти', 'продукты']];
        yield 'англ. слово на укр. розкладці' => ['фізшкшт', ['aspirin']];
        yield 'англ. слово на рос. розкладці' => ['фызшкшт', ['aspirin']];
        yield 'регістр' => ['GHJLERNB', ['продукти', 'продукты']];
        yield 'змішаний — без спроби' => ['bosch дриль', []];
        yield 'без літер' => ['12345', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('layouts')]
    public function testKeyboardLayoutAlternatives(string $query, array $expected): void
    {
        self::assertSame($expected[0] ?? null, KeyboardLayout::alternatives($query)[0] ?? null);
        if ('' !== ($expected[0] ?? '')) {
            self::assertNotContains($query, KeyboardLayout::alternatives($query));
        }
    }
}
