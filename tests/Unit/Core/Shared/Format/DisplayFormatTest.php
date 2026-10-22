<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Shared\Format;

use App\Core\Catalog\Enum\SalesUnit;
use App\Core\Shared\Format\DisplayFormat;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Twig\FormatExtension;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader as TwigArrayLoader;

/** Формат для людей (UX-аудит 2026-10-18, PR-1): гроші «1 588,00 ₴», кількість «32 шт» / «2,5 м», дата «18.10.2026 11:07». */
final class DisplayFormatTest extends TestCase
{
    private const string NBSP = "\u{a0}";

    public function testMoneyUsesLocaleSeparatorsAndSymbolWithNonBreakingSpaces(): void
    {
        self::assertSame('1'.self::NBSP.'588,00'.self::NBSP.'₴', DisplayFormat::money(Money::of(158_800, 'UAH'), 'uk'));
        self::assertSame('0,00'.self::NBSP.'₴', DisplayFormat::money(Money::zero('UAH'), 'uk'));
        self::assertSame('-1'.self::NBSP.'035,50'.self::NBSP.'₴', str_replace("\u{2212}", '-', DisplayFormat::money(Money::of(-103_550, 'UAH'), 'uk')));
        self::assertSame('UAH 1,588.00', str_replace(self::NBSP, ' ', DisplayFormat::money(Money::of(158_800, 'UAH'), 'en')));
    }

    public function testQuantityDropsTrailingZerosAndGroupsThousands(): void
    {
        self::assertSame('1', DisplayFormat::quantity('1.000', 'uk'), 'штучне — без «.000»');
        self::assertSame('32', DisplayFormat::quantity('32.000', 'uk'));
        self::assertSame('2,5', DisplayFormat::quantity('2.500', 'uk'));
        self::assertSame('0,125', DisplayFormat::quantity('0.125', 'uk'));
        self::assertSame('1'.self::NBSP.'250', DisplayFormat::quantity('1250.000', 'uk'));
        self::assertSame('0', DisplayFormat::quantity('0.000', 'uk'));
        self::assertSame('2.5', DisplayFormat::quantity('2.500', 'en'));
        self::assertSame('7', DisplayFormat::quantity(7, 'uk'));
    }

    public function testDateIsNumericWithOptionalTimeWithoutSeconds(): void
    {
        $tz = date_default_timezone_get();
        date_default_timezone_set('Europe/Kyiv');
        try {
            $at = new DateTimeImmutable('2026-10-18 08:07:59', new DateTimeZone('UTC'));
            self::assertSame('18.10.2026', DisplayFormat::date($at, 'uk'));
            self::assertSame('18.10.2026 11:07', DisplayFormat::date($at, 'uk', true), 'час — у поясі застосунку, як у Twig |date');
            self::assertSame('18.10.2026 11:07', DisplayFormat::date($at, 'ru', true));
            self::assertSame('10/18/2026 11:07', DisplayFormat::date($at, 'en', true));
        } finally {
            date_default_timezone_set($tz);
        }
    }

    public function testTwigFilters(): void
    {
        $translator = new Translator('uk');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['pc' => 'шт', 'm' => 'м', 'm2' => 'м²'], 'uk', 'units');
        $translator->addResource('array', ['pc' => 'pcs', 'm' => 'm'], 'en', 'units');
        $twig = new Environment(new TwigArrayLoader([
            't' => '{{ total|money }}|{{ none|money }}|{{ q|qty(unit) }}|{{ q|qty("m2") }}|{{ q|qty }}|{{ q|qty("pc", "en") }}|{{ at|date_short }}|{{ at|date_short(true) }}|{{ empty|date_short }}',
        ]), ['strict_variables' => true]);
        $twig->addExtension(new FormatExtension($translator));

        $out = $twig->render('t', ['total' => Money::of(158_800, 'UAH'), 'none' => null, 'q' => '32.000', 'unit' => SalesUnit::Piece,
            'at' => new DateTimeImmutable('2026-10-18 11:07:00'), 'empty' => null]);

        self::assertSame('1 588,00 ₴||32 шт|32 м²|32|32 pcs|18.10.2026|18.10.2026 11:07|', str_replace(self::NBSP, ' ', $out));
        self::assertStringContainsString('32'.self::NBSP.'шт', $out, 'кількість і одиниця не розриваються');
    }
}
