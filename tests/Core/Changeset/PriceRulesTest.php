<?php

declare(strict_types=1);

namespace App\Tests\Core\Changeset;

use App\Core\Changeset\Plan\Issue;
use App\Core\Changeset\Validate\AuditLookup;
use App\Core\Changeset\Validate\PriceRules;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/** Цінові правила v1: кожне — ризик (не помилка), поріг з параметра, 0 = вимкнено; відсотки без float. */
final class PriceRulesTest extends TestCase
{
    public function testJumpZeroAndMassWithDefaults(): void
    {
        $rules = $this->rules();
        self::assertSame([], self::codes($rules->variantPrice(0, 1, 'A', 10_000, 13_000)), 'Рівно 30 % — не стрибок');
        self::assertSame([PriceRules::PRICE_JUMP], self::codes($rules->variantPrice(0, 1, 'A', 10_000, 13_001)));
        self::assertSame([PriceRules::PRICE_JUMP], self::codes($rules->variantPrice(0, 1, 'A', 10_000, 6_999)));
        self::assertSame([PriceRules::ZERO_PRICE, PriceRules::PRICE_JUMP], self::codes($rules->variantPrice(0, 1, 'A', 10_000, 0)));
        self::assertSame([], self::codes($rules->variantPrice(0, 1, 'A', 0, 5_000)), 'Від нуля стрибок не рахується');
        self::assertSame(30, $rules->variantPrice(0, 1, 'A', 10_000, 13_001)[0]->details['pct']);
        self::assertNull($rules->massPrice(50));
        self::assertSame(PriceRules::MASS_PRICE, $rules->massPrice(51)?->code);
        self::assertSame(Issue::RISK, $rules->massPrice(51)->kind);
        self::assertSame(PriceRules::TIER_ABOVE_BASE, $rules->tier(0, 'A', '10', 10_001, 10_000)?->code);
        self::assertNull($rules->tier(0, 'A', '10', 10_000, 10_000));
    }

    public function testCumulativeUsesAuditBaseline(): void
    {
        $rules = $this->rules(baseline: 10_000);
        self::assertSame([PriceRules::CUMULATIVE], self::codes($rules->variantPrice(0, 1, 'A', 8_000, 6_000)), '−20 % зараз, але −40 % від базової тиждень тому');
        self::assertSame('100.00', $rules->variantPrice(0, 1, 'A', 8_000, 6_000)[0]->details['baseline']);
        self::assertSame([], self::codes($this->rules(baseline: null)->variantPrice(0, 1, 'A', 8_000, 6_000)));
    }

    public function testZeroThresholdsDisableRules(): void
    {
        $rules = $this->rules(jump: 0, cumulative: 0, mass: 0, zero: false, baseline: 10_000);
        self::assertSame([], self::codes($rules->variantPrice(0, 1, 'A', 10_000, 0)));
        self::assertNull($rules->massPrice(1_000));
    }

    private function rules(int $jump = 30, int $cumulative = 30, int $days = 7, int $mass = 50, bool $zero = true, ?int $baseline = null): PriceRules
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturn(null === $baseline ? false : (string) $baseline);

        return new PriceRules(new AuditLookup($db), $jump, $cumulative, $days, $mass, $zero);
    }

    /**
     * @param list<Issue> $issues
     *
     * @return list<string>
     */
    private static function codes(array $issues): array
    {
        return array_map(static fn (Issue $i): string => $i->code, $issues);
    }
}
