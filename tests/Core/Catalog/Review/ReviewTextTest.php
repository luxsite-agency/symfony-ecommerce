<?php

declare(strict_types=1);

namespace App\Tests\Core\Catalog\Review;

use App\Core\Catalog\Review\ReviewText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Текст відгуку: HTML і посилання відхиляються, звичайний текст з порівняннями, крапками й амперсандами — ні; текст не правиться. */
final class ReviewTextTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function texts(): iterable
    {
        yield 'звичайний' => ['Чудові двері, встановили за день. Рекомендую!', null];
        yield 'порівняння' => ['Ціна 5 < 7, якість > очікувань', null];
        yield 'амперсанд і крапки' => ['Procter & Gamble, версія 1.2.3, т.д.', null];
        yield 'тег' => ['Супер <b>товар</b>', 'html'];
        yield 'script' => ['x<script>alert(1)</script>', 'html'];
        yield 'закривальний тег' => ['текст</p>', 'html'];
        yield 'сутність' => ['&lt;b&gt;жирний', 'html'];
        yield 'https' => ['дивіться https://example.com/x', 'link'];
        yield 'www' => ['дивіться www.example.com', 'link'];
        yield 'домен без схеми' => ['купуйте на shop.example.com.ua/sale', 'link'];
        yield 'домен ua' => ['краще на rozetka.ua', 'link'];
        yield 'mailto' => ['пишіть mailto:me@example.com', 'link'];
    }

    #[DataProvider('texts')]
    public function testProblem(string $text, ?string $expected): void
    {
        self::assertSame($expected, ReviewText::problem($text));
    }

    public function testNormalizeTrimsEdgesOnly(): void
    {
        self::assertSame("Два  пробіли   лишаються\nі рядок", ReviewText::normalize("  Два  пробіли   лишаються\r\nі рядок \n"), 'лише краї й CRLF; слова не чіпаємо');
        self::assertSame('«Лапки» -- дефіси ... крапки', ReviewText::normalize('«Лапки» -- дефіси ... крапки'), 'жодної типографіки');
    }
}
