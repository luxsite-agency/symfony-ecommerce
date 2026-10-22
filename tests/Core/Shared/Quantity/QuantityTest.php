<?php

declare(strict_types=1);

namespace App\Tests\Core\Shared\Quantity;

use App\Core\Shared\Quantity\Quantity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuantityTest extends TestCase
{
    public function testNormalizesToScaleThree(): void
    {
        self::assertSame('2.500', Quantity::normalize('2,5'));
        self::assertSame('45.000', Quantity::normalize(' 45 '));
        self::assertSame('0.000', Quantity::normalize('-0'));
        self::assertSame('-1.250', Quantity::normalize('-1.25'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidProvider(): iterable
    {
        yield 'порожньо' => [''];
        yield 'чотири знаки після коми' => ['1.2345'];
        yield 'десять цифр' => ['1234567890'];
        yield 'експонента' => ['1e3'];
        yield 'текст' => ['два'];
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsInvalid(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        Quantity::normalize($value);
    }

    public function testPositiveRejectsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Quantity::positive('0.000');
    }

    public function testNonNegativeAcceptsZeroAndRejectsNegative(): void
    {
        self::assertSame('0.000', Quantity::nonNegative('0'));
        $this->expectException(InvalidArgumentException::class);
        Quantity::nonNegative('-0.001');
    }

    public function testCompare(): void
    {
        self::assertSame(0, Quantity::compare('1', '1.000'));
        self::assertSame(1, Quantity::compare('1.001', '1'));
        self::assertSame(-1, Quantity::compare('0.5', '2'));
    }
}
