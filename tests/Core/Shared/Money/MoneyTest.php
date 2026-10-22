<?php

declare(strict_types=1);

namespace App\Tests\Core\Shared\Money;

use App\Core\Shared\Money\CurrencyMismatchException;
use App\Core\Shared\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testArithmetic(): void
    {
        $a = Money::of(10_050, 'UAH');
        $b = Money::of(950, 'uah');

        self::assertSame(11_000, $a->add($b)->amount);
        self::assertSame(9_100, $a->subtract($b)->amount);
        self::assertSame(30_150, $a->multiply(3)->amount);
        self::assertSame(-10_050, $a->negate()->amount);
        self::assertSame('UAH', $b->currency);
    }

    public function testCurrencyMismatch(): void
    {
        $this->expectException(CurrencyMismatchException::class);
        Money::of(1, 'UAH')->add(Money::of(1, 'USD'));
    }

    #[DataProvider('decimalStrings')]
    public function testFromDecimalString(string $input, int $expected): void
    {
        self::assertSame($expected, Money::fromDecimalString($input, 'UAH')->amount);
    }

    /** @return iterable<string, array{string, int}> */
    public static function decimalStrings(): iterable
    {
        yield 'plain' => ['1234.56', 123_456];
        yield 'comma' => ['1234,5', 123_450];
        yield 'spaces' => ['1 234.56', 123_456];
        yield 'integer' => ['15', 1_500];
        yield 'rounds half up' => ['0.005', 1];
        yield 'negative' => ['-3.10', -310];
    }

    public function testInvalidDecimalString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimalString('12.3.4', 'UAH');
    }

    #[DataProvider('decimalQuantities')]
    public function testMultiplyByDecimal(int $unit, string $qty, int $expected): void
    {
        self::assertSame($expected, Money::of($unit, 'UAH')->multiplyByDecimal($qty)->amount);
    }

    /** @return iterable<string, array{int, string, int}> */
    public static function decimalQuantities(): iterable
    {
        yield 'лиштва 2,5 шт' => [125_000, '2.5', 312_500];
        yield 'метал 1.333 м' => [44_999, '1.333', 59_984];   // 59 983.667 → half-up
        yield 'ціле' => [999, '3', 2_997];
        yield 'кома як роздільник' => [1_000, '0,5', 500];
        yield 'нуль' => [1_000, '0', 0];
    }

    public function testPercentageHalfUp(): void
    {
        self::assertSame(1_500, Money::of(10_000, 'UAH')->percentage(1_500)->amount); // 15 %
        self::assertSame(1, Money::of(10, 'UAH')->percentage(500)->amount);            // 0.5 → 1
        self::assertSame(-150, Money::of(-1_000, 'UAH')->percentage(1_500)->amount);
    }

    public function testComparisons(): void
    {
        $a = Money::of(100, 'UAH');
        $b = Money::of(200, 'UAH');

        self::assertTrue($a->lessThan($b));
        self::assertTrue($b->greaterThan($a));
        self::assertTrue($a->equals(Money::of(100, 'UAH')));
        self::assertFalse($a->equals(Money::of(100, 'USD')));
        self::assertSame($a, $a->min($b));
        self::assertSame($b, $a->max($b));
        self::assertTrue(Money::zero('UAH')->isZero());
        self::assertTrue(Money::of(-1, 'UAH')->isNegative());
    }

    public function testDecimalStringAndJson(): void
    {
        self::assertSame('1234.56', Money::of(123_456, 'UAH')->toDecimalString());
        self::assertSame('0.05', Money::of(5, 'UAH')->toDecimalString());
        self::assertSame('-0.05', Money::of(-5, 'UAH')->toDecimalString());
        self::assertSame('7', Money::of(7, 'JPY')->toDecimalString(0));
        self::assertSame('{"amount":5,"currency":"UAH"}', json_encode(Money::of(5, 'UAH')));
        self::assertSame('12.00 USD', (string) Money::of(1_200, 'USD'));
    }

    public function testFormatByLocale(): void
    {
        self::assertStringContainsString('1', Money::of(123_456, 'UAH')->format('uk'));
        self::assertSame('$1,234.56', Money::of(123_456, 'USD')->format('en_US'));
    }

    public function testRejectsBadCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(1, 'UAHH');
    }
}
