<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\Dashboard\DashboardRange;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Період дашборда: пресети, довільний діапазон, строгий розбір, межі включно, крок графіка, ключ кешу. */
final class DashboardRangeTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-11 15:42:10');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function presets(): iterable
    {
        yield 'сьогодні' => ['today', '2026-10-11', '2026-10-11'];
        yield 'вчора' => ['yesterday', '2026-10-10', '2026-10-10'];
        yield '7 днів включно з сьогодні' => ['7', '2026-10-05', '2026-10-11'];
        yield '30 днів' => ['30', '2026-09-12', '2026-10-11'];
        yield '90 днів' => ['90', '2026-07-14', '2026-10-11'];
        yield 'цей місяць до сьогодні' => ['month', '2026-10-01', '2026-10-11'];
        yield 'минулий місяць цілком' => ['last_month', '2026-09-01', '2026-09-30'];
    }

    #[DataProvider('presets')]
    public function testPresets(string $key, string $from, string $to): void
    {
        $range = DashboardRange::fromQuery($key, null, null, $this->now);
        self::assertSame($from, $range->from->format('Y-m-d'));
        self::assertSame($to, $range->to->format('Y-m-d'));
        self::assertSame('00:00:00', $range->from->format('H:i:s'), 'початок дня');
        self::assertTrue($range->isPreset($key));
        self::assertNull($range->error);
        self::assertSame(['period' => $key], $range->query());
    }

    public function testLastMonthAcrossYearAndMonthLengths(): void
    {
        $range = DashboardRange::preset('last_month', new DateTimeImmutable('2026-01-15'));
        self::assertSame(['2025-12-01', '2025-12-31'], [$range->from->format('Y-m-d'), $range->to->format('Y-m-d')]);
        $range = DashboardRange::preset('last_month', new DateTimeImmutable('2026-03-31'));
        self::assertSame(['2026-02-01', '2026-02-28'], [$range->from->format('Y-m-d'), $range->to->format('Y-m-d')], 'не «31 лютого»');
        self::assertSame(28, $range->days());
    }

    public function testCustomRangeInclusiveBounds(): void
    {
        $range = DashboardRange::fromQuery(null, '2026-09-01', '2026-09-10', $this->now);
        self::assertSame('custom', $range->preset);
        self::assertNull($range->error);
        self::assertSame('2026-09-01 00:00:00', $range->start()->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-11 00:00:00', $range->endExclusive()->format('Y-m-d H:i:s'), 'кінець включно: до початку наступного дня');
        self::assertSame(10, $range->days());
        self::assertSame('2026-09-01_2026-09-10', $range->cacheKey());
        self::assertSame(['from' => '2026-09-01', 'to' => '2026-09-10'], $range->query());
        self::assertSame('day', $range->granularity());
    }

    public function testCustomRangeEqualToPresetIsRecognisedAsPreset(): void
    {
        $range = DashboardRange::fromQuery('7', '2026-09-12', '2026-10-11', $this->now);
        self::assertTrue($range->isPreset('30'), 'from/to мають пріоритет над period і збігаються з пресетом 30');
        self::assertSame('2026-09-12_2026-10-11', $range->cacheKey(), 'той самий ключ кешу, що в пресету');
        self::assertSame(DashboardRange::preset('30', $this->now)->cacheKey(), $range->cacheKey());
    }

    /** @return iterable<string, array{?string, ?string, ?string, string}> */
    public static function invalid(): iterable
    {
        yield 'не дата' => [null, 'вчора', '2026-10-11', 'invalid'];
        yield 'неіснуючий день' => [null, '2026-02-30', '2026-03-01', 'invalid'];
        yield 'хвіст після дати' => [null, '2026-10-01T00:00', '2026-10-11', 'invalid'];
        yield 'SQL у даті' => [null, "2026-10-01' OR 1=1", '2026-10-11', 'invalid'];
        yield 'лише from' => [null, '2026-10-01', '', 'invalid'];
        yield 'from пізніше за to' => [null, '2026-10-11', '2026-10-01', 'order'];
        yield 'to у майбутньому' => [null, '2026-10-01', '2026-10-12', 'future'];
        yield 'понад 731 день' => [null, '2024-10-10', '2026-10-11', 'too_long'];
        yield 'невідомий пресет' => ['bogus', null, null, 'preset'];
    }

    #[DataProvider('invalid')]
    public function testInvalidInputFallsBackToDefault(?string $period, ?string $from, ?string $to, string $error): void
    {
        $range = DashboardRange::fromQuery($period, $from, $to, $this->now);
        self::assertSame($error, $range->error);
        self::assertTrue($range->isPreset('30'), 'типові 30 днів');
        self::assertSame('2026-09-12', $range->from->format('Y-m-d'));
        self::assertSame('2026-10-11', $range->to->format('Y-m-d'));
    }

    public function testMaxRangeAndEmptyQuery(): void
    {
        $range = DashboardRange::fromQuery(null, '2024-10-11', '2026-10-11', $this->now);
        self::assertNull($range->error, 'рівно 731 день приймається');
        self::assertSame(731, $range->days());
        $range = DashboardRange::fromQuery('', '', '', $this->now);
        self::assertTrue($range->isPreset('30'));
        self::assertNull($range->error, 'порожній запит — типовий період без повідомлення');
    }

    public function testGranularityByLength(): void
    {
        self::assertSame('day', DashboardRange::fromQuery(null, '2026-07-12', '2026-10-11', $this->now)->granularity(), '92 дні — по днях');
        self::assertSame('week', DashboardRange::fromQuery(null, '2026-07-11', '2026-10-11', $this->now)->granularity(), '93 дні — по тижнях');
        self::assertSame('week', DashboardRange::fromQuery(null, '2025-10-11', '2026-10-11', $this->now)->granularity(), '366 днів — по тижнях');
        self::assertSame('month', DashboardRange::fromQuery(null, '2025-10-10', '2026-10-11', $this->now)->granularity(), '367 днів — по місяцях');
    }
}
