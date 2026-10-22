<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\Dashboard\DashboardStats;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Шкала графіка продажів: «круглий» крок у цілих гривнях, до максимуму не більше 3 кроків. */
final class DashboardAxisTest extends TestCase
{
    /** @return iterable<string, array{int, int}> максимум (копійки) → крок (копійки) */
    public static function steps(): iterable
    {
        yield 'нуль' => [0, 100];
        yield '1 грн' => [100, 100];
        yield '1500 грн' => [150_000, 50_000];
        yield '3300 грн' => [330_000, 200_000];
        yield '6645 грн' => [664_500, 500_000];
        yield '3000 грн рівно' => [300_000, 100_000];
        yield '1,2 млн' => [120_000_000, 50_000_000];
    }

    #[DataProvider('steps')]
    public function testStep(int $max, int $step): void
    {
        self::assertSame($step, DashboardStats::axisStep($max));
        self::assertLessThanOrEqual(3, (int) ceil($max / $step), 'не більше 3 кроків — 4 позначки з нулем');
    }
}
