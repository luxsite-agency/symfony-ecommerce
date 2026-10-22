<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Validate;

use App\Core\Changeset\Plan\Issue;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Правила цін v1 (consilium §4 з рішенням власника 11.10, п. 5): усе — попередження з ризиковим прапорцем, не блокування;
 * застосовує менеджер з явним підтвердженням. Пороги — параметри app.ai_*, 0 = правило вимкнене. Суми — мінорні одиниці,
 * відсотки — цілочисельно (без float). Тексти — IssueMessage: український для API, ключ changeset.issue.* для адмінки.
 */
final class PriceRules
{
    public const string ZERO_PRICE = 'zero_price';
    public const string PRICE_JUMP = 'price_jump';
    public const string CUMULATIVE = 'cumulative';
    public const string MASS_PRICE = 'mass_price';
    public const string TIER_ABOVE_BASE = 'tier_above_base';

    public function __construct(
        private readonly AuditLookup $audit,
        #[Autowire('%app.ai_price_jump_pct%')] private readonly int $jumpPct,
        #[Autowire('%app.ai_price_cumulative_pct%')] private readonly int $cumulativePct,
        #[Autowire('%app.ai_price_cumulative_days%')] private readonly int $cumulativeDays,
        #[Autowire('%app.ai_mass_price_ops%')] private readonly int $massPriceOps,
        #[Autowire('%app.ai_flag_zero_price%')] private readonly bool $flagZeroPrice,
    ) {
    }

    /**
     * Нова базова ціна варіанта.
     *
     * @return list<Issue>
     */
    public function variantPrice(int $index, int $variantId, string $sku, int $before, int $after): array
    {
        $issues = [];
        if ($this->flagZeroPrice && 0 === $after) {
            $issues[] = Issue::risk($index, self::ZERO_PRICE, IssueMessage::of('changeset.issue.zero_price', ['sku' => $sku], \sprintf('%s: ціна 0', $sku)), ['variant' => $variantId]);
        }
        if ($this->jumpPct > 0 && $before > 0 && self::exceeds($before, $after, $this->jumpPct)) {
            $issues[] = Issue::risk($index, self::PRICE_JUMP, IssueMessage::of('changeset.issue.price_jump', ['sku' => $sku, 'pct' => (string) $this->jumpPct, 'before' => self::decimal($before), 'after' => self::decimal($after)], \sprintf('%s: зміна ціни понад %d %% (%s → %s)', $sku, $this->jumpPct, self::decimal($before), self::decimal($after))), ['variant' => $variantId, 'pct' => self::pct($before, $after)]);
        }
        if ($this->cumulativePct > 0 && $this->cumulativeDays > 0) {
            $baseline = $this->audit->priceBaseline($variantId, $this->cumulativeDays);
            if (null !== $baseline && $baseline > 0 && self::exceeds($baseline, $after, $this->cumulativePct)) {
                $issues[] = Issue::risk($index, self::CUMULATIVE, IssueMessage::of('changeset.issue.cumulative', ['sku' => $sku, 'days' => (string) $this->cumulativeDays, 'pct' => (string) $this->cumulativePct, 'before' => self::decimal($baseline), 'after' => self::decimal($after)], \sprintf('%s: сукупна зміна за %d дн. понад %d %% (%s → %s)', $sku, $this->cumulativeDays, $this->cumulativePct, self::decimal($baseline), self::decimal($after))), ['variant' => $variantId, 'baseline' => self::decimal($baseline), 'pct' => self::pct($baseline, $after)]);
            }
        }

        return $issues;
    }

    /** Поріг дорожчий за базову ціну (з урахуванням нової базової з того ж пакета) — попередження. */
    public function tier(int $index, string $sku, string $minQuantity, int $tierPrice, int $basePrice): ?Issue
    {
        if ($tierPrice <= $basePrice) {
            return null;
        }

        return Issue::warning($index, self::TIER_ABOVE_BASE, IssueMessage::of('changeset.issue.tier_above_base', ['sku' => $sku, 'qty' => $minQuantity, 'price' => self::decimal($tierPrice), 'base' => self::decimal($basePrice)], \sprintf('%s: поріг від %s (%s) дорожчий за базову ціну (%s)', $sku, $minQuantity, self::decimal($tierPrice), self::decimal($basePrice))));
    }

    public function massPrice(int $priceOperations): ?Issue
    {
        if ($this->massPriceOps <= 0 || $priceOperations <= $this->massPriceOps) {
            return null;
        }

        return Issue::risk(null, self::MASS_PRICE, IssueMessage::of('changeset.issue.mass_price', ['count' => (string) $priceOperations, 'max' => (string) $this->massPriceOps], \sprintf('Цінових операцій у пакеті: %d (поріг %d)', $priceOperations, $this->massPriceOps)), ['count' => $priceOperations, 'max' => $this->massPriceOps]);
    }

    private static function exceeds(int $base, int $new, int $pct): bool
    {
        return abs($new - $base) * 100 > $base * $pct;
    }

    private static function pct(int $base, int $new): int
    {
        return $base > 0 ? intdiv(abs($new - $base) * 100, $base) : 0;
    }

    private static function decimal(int $minor): string
    {
        return \sprintf('%s%d.%02d', $minor < 0 ? '-' : '', intdiv(abs($minor), 100), abs($minor) % 100);
    }
}
