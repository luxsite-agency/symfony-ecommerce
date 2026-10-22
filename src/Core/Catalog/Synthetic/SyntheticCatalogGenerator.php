<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Synthetic;

use App\Core\Catalog\Enum\AttributeType;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Enum\StockPolicy;
use Generator;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Детермінований генератор синтетичного каталогу (протокол Ф1, ADR Rev 9 D3): дерево категорій глибини 3 заданого
 * розміру, товари по листах за Zipf (кілька величезних категорій і довгий хвіст), бренди за Zipf, N карткових
 * характеристик різних типів із покриттям 100 % → 55 % і значеннями за Zipf, вісь варіанта «розмір» (1 варіант у 60 %
 * карток, інакше 2…max), ~25 % варіантів із трекінгом залишку — 0 (OOS), 2 % вимкнених карток, 1 % «ціна за запитом».
 *
 * Лише Random\Randomizer(Mt19937(seed)) — без глобального mt_rand, тож чужий код не збиває послідовність;
 * products() щоразу починає потік заново: той самий spec + seed → той самий каталог у SQL- та import-режимі.
 * У пам'яті — лише дерево й довідники, картки віддаються генератором по одній.
 */
final class SyntheticCatalogGenerator
{
    public const string AXIS_CODE = 'syn_size';
    public const int AXIS_VALUES = 12;
    private const float SINGLE_VARIANT_SHARE = 0.6;
    private const float OOS_SHARE = 0.25;

    /** @var list<SyntheticCategory> */
    private array $categories = [];
    /** @var list<int> індекси листів у порядку «популярності» (перемішані, щоб великі листи були в різних розділах) */
    private array $leaves = [];
    /** @var list<SyntheticAttribute> */
    private array $attributes = [];

    public function __construct(public readonly SyntheticCatalogSpec $spec)
    {
        $this->buildCategories();
        $this->buildAttributes();
    }

    /** @return list<SyntheticCategory> батьки раніше за дітей, index = позиція + 1 */
    public function categories(): array
    {
        return $this->categories;
    }

    /** @return list<int> */
    public function leaves(): array
    {
        return $this->leaves;
    }

    /** @return list<SyntheticAttribute> карткові, потім вісь (якщо варіантів може бути більше одного) */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function axis(): ?SyntheticAttribute
    {
        $last = end($this->attributes);

        return false !== $last && $last->axis ? $last : null;
    }

    /** @return Generator<int, SyntheticProduct> ключ — номер картки 1…products */
    public function products(): Generator
    {
        $spec = $this->spec;
        $random = new Randomizer(new Mt19937($spec->seed));
        $leafSampler = new ZipfSampler(\count($this->leaves), 1.0);
        $brandSampler = new ZipfSampler($spec->brands, 1.1);
        $valueSamplers = [];
        foreach ($this->attributes as $attribute) {
            if ($attribute->valueCount > 0) {
                $valueSamplers[$attribute->index] = new ZipfSampler($attribute->valueCount, 0.9);
            }
        }
        $axis = $this->axis();
        $leafCount = \count($this->leaves);
        $lnMin = log(100);
        $lnMax = log(100_000);

        for ($n = 1; $n <= $spec->products; ++$n) {
            $categories = [$this->leaves[$leafSampler->sample($random)]];
            if ($leafCount > 1 && $random->nextFloat() < 0.05) {
                $extra = $this->leaves[$random->getInt(0, $leafCount - 1)];
                if ($extra !== $categories[0]) {
                    $categories[] = $extra;
                }
            }
            $brand = $random->nextFloat() < 0.05 ? null : $brandSampler->sample($random) + 1;
            $active = $random->nextFloat() >= 0.02;
            $modeRoll = $random->nextFloat();
            $priceMode = $modeRoll < 0.96 ? PriceMode::Fixed : ($modeRoll < 0.99 ? PriceMode::From : PriceMode::OnRequest);

            $attributes = [];
            foreach ($this->attributes as $attribute) {
                if ($attribute->axis || $random->nextFloat() >= $attribute->coverage) {
                    continue;
                }
                $attributes[$attribute->index] = match ($attribute->type) {
                    AttributeType::Select => $valueSamplers[$attribute->index]->sample($random) + 1,
                    AttributeType::MultiSelect => $this->multi($random, $valueSamplers[$attribute->index], $attribute->valueCount),
                    AttributeType::Number => $this->number($random, $attribute),
                    AttributeType::Boolean => $random->nextFloat() < 0.5,
                };
            }

            $variantCount = null === $axis || $random->nextFloat() < self::SINGLE_VARIANT_SHARE ? 1 : $random->getInt(2, $spec->maxVariants);
            $axisStart = $variantCount > 1 ? $random->getInt(1, $axis->valueCount - $variantCount + 1) : null;
            $base = (int) round(exp($lnMin + $random->nextFloat() * ($lnMax - $lnMin))) * 100;
            $variants = [];
            for ($v = 0; $v < $variantCount; ++$v) {
                $price = (int) (round($base * (1 + 0.05 * $v) / 100) * 100);
                $policyRoll = $random->nextFloat();
                $policy = $policyRoll < 0.95 ? StockPolicy::InStock : ($policyRoll < 0.98 ? StockPolicy::MadeToOrder : StockPolicy::NotTracked);
                $stock = StockPolicy::InStock === $policy && $random->nextFloat() >= self::OOS_SHARE ? $random->getInt(1, 50) : 0;
                $old = $random->nextFloat() < 0.1 ? (int) (round($price * 1.2 / 100) * 100) : null;
                $variants[] = new SyntheticVariant($price, $old, $stock, $policy, null === $axisStart ? null : $axisStart + $v);
            }

            yield $n => new SyntheticProduct($n, $categories, $brand, $active, $priceMode, $attributes, $variants);
        }
    }

    /**
     * Дерево глибини ≤ 3 рівно з spec->categories вузлів: r розділів, r² груп, решта — підгрупи, розкладені по групах
     * по колу. 463 → 7 + 49 + 407. Товари — лише в листах (вузлах без дітей).
     */
    private function buildCategories(): void
    {
        $total = $this->spec->categories;
        $roots = max(1, min($total, (int) round($total ** (1 / 3) * 0.9)));
        $groups = min($total - $roots, $roots * $roots);
        $index = 0;
        $children = [];
        for ($r = 1; $r <= $roots; ++$r) {
            $this->categories[] = new SyntheticCategory(++$index, null, 0, (string) $r, $r);
        }
        $groupIndexes = [];
        for ($g = 0; $g < $groups; ++$g) {
            $parent = $this->categories[$g % $roots];
            $position = intdiv($g, $roots) + 1;
            $this->categories[] = new SyntheticCategory(++$index, $parent->index, 1, $parent->label.'-'.$position, $position);
            $groupIndexes[] = $index;
            $children[$parent->index] = true;
        }
        for ($s = 0; $index < $total; ++$s) {
            $parent = $this->categories[$groupIndexes[$s % \count($groupIndexes)] - 1];
            $position = intdiv($s, \count($groupIndexes)) + 1;
            $this->categories[] = new SyntheticCategory(++$index, $parent->index, 2, $parent->label.'-'.$position, $position);
            $children[$parent->index] = true;
        }
        $leaves = [];
        foreach ($this->categories as $category) {
            if (!isset($children[$category->index])) {
                $leaves[] = $category->index;
            }
        }
        $this->leaves = (new Randomizer(new Mt19937($this->spec->seed + 1)))->shuffleArray($leaves);
    }

    /** Типи по колу: select різної «ширини», число, boolean, multiselect; покриття спадає від 100 % до 55 %. */
    private function buildAttributes(): void
    {
        $plan = [
            [AttributeType::Select, 8], [AttributeType::Select, 20], [AttributeType::Number, 0], [AttributeType::Boolean, 0],
            [AttributeType::Select, 5], [AttributeType::MultiSelect, 12], [AttributeType::Select, 50], [AttributeType::Number, 1],
            [AttributeType::Select, 100], [AttributeType::Boolean, 0], [AttributeType::Select, 3], [AttributeType::Select, 30],
        ];
        for ($k = 0; $k < $this->spec->attributes; ++$k) {
            [$type, $param] = $plan[$k % \count($plan)];
            $coverage = max(0.55, 1.0 - 0.05 * $k);
            $code = \sprintf('syn_a%02d', $k + 1);
            $this->attributes[] = match ($type) {
                AttributeType::Number => 0 === $param
                    ? new SyntheticAttribute($k + 1, $code, $type, coverage: $coverage, numberMin: 10, numberMax: 3000, unit: 'mm')
                    : new SyntheticAttribute($k + 1, $code, $type, coverage: $coverage, numberMin: 1, numberMax: 999, decimals: 1, unit: 'kg'),
                AttributeType::Boolean => new SyntheticAttribute($k + 1, $code, $type, coverage: $coverage),
                default => new SyntheticAttribute($k + 1, $code, $type, valueCount: $param, coverage: $coverage),
            };
        }
        if ($this->spec->maxVariants > 1) {
            $this->attributes[] = new SyntheticAttribute($this->spec->attributes + 1, self::AXIS_CODE, AttributeType::Select, valueCount: max(self::AXIS_VALUES, $this->spec->maxVariants), axis: true);
        }
    }

    /** @return list<int> 1–3 різні значення, відсортовані */
    private function multi(Randomizer $random, ZipfSampler $sampler, int $valueCount): array
    {
        $values = [];
        $want = $random->getInt(1, min(3, $valueCount));
        for ($i = 0; $i < $want * 3 && \count($values) < $want; ++$i) {
            $values[$sampler->sample($random) + 1] = true;
        }
        $list = array_keys($values);
        sort($list);

        return $list;
    }

    private function number(Randomizer $random, SyntheticAttribute $attribute): string
    {
        if (0 === $attribute->decimals) {
            return (string) $random->getInt($attribute->numberMin, $attribute->numberMax);
        }
        $scale = 10 ** $attribute->decimals;

        return number_format($random->getInt($attribute->numberMin * $scale, $attribute->numberMax * $scale) / $scale, $attribute->decimals, '.', '');
    }
}
