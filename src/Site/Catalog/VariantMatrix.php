<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Catalog;

/**
 * Вибір варіанта за осями (колір × розмір) без JS: сторінка товару отримує обрані значення осей із GET
 * (?<код осі>=<код значення>), вибирає варіант і для кожного значення кожної осі будує посилання на найближчий
 * варіант. «Найближчий» — з цим значенням осі й максимумом збігів із поточним варіантом на інших осях; за рівності —
 * той, що можна купити, далі — за порядком варіантів. Так клік по «42» не скидає обраний колір, якщо 42 цього кольору є.
 *
 * Ключ варіанта — довільний int (id), axes — код осі → код значення.
 */
final readonly class VariantMatrix
{
    /**
     * @param list<array{code: string, options: list<string>}>                    $axes     осі в порядку показу, значення в порядку position
     * @param list<array{key: int, axes: array<string, string>, available: bool}> $variants у порядку показу
     */
    public function __construct(public array $axes, public array $variants)
    {
    }

    /**
     * Варіант під запит: максимум збігів із запитаними осями, далі доступний, далі перший. Без запиту — перший доступний.
     *
     * @param array<string, string> $requested
     */
    public function select(array $requested): ?int
    {
        $best = null;
        $bestScore = null;
        foreach ($this->variants as $position => $variant) {
            $matches = \count(array_intersect_assoc($requested, $variant['axes']));
            $score = [$matches, $variant['available'] ? 1 : 0, -$position];
            if (null === $bestScore || $score > $bestScore) {
                $best = $variant['key'];
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Обрані значення осей варіанта (для посилань і підсвітки).
     *
     * @return array<string, string>
     */
    public function axesOf(int $key): array
    {
        foreach ($this->variants as $variant) {
            if ($variant['key'] === $key) {
                return $variant['axes'];
            }
        }

        return [];
    }

    /**
     * Значення осей для перемикача: лише ті, для яких є варіант; target — повний набір осей варіанта, на який веде клік.
     *
     * @return list<array{code: string, options: list<array{value: string, selected: bool, available: bool, exact: bool, target: array<string, string>}>}>
     */
    public function choices(int $selectedKey): array
    {
        $current = $this->axesOf($selectedKey);
        $choices = [];
        foreach ($this->axes as $axis) {
            $code = $axis['code'];
            $options = [];
            foreach ($axis['options'] as $value) {
                $target = null;
                $bestScore = null;
                foreach ($this->variants as $position => $variant) {
                    if (($variant['axes'][$code] ?? null) !== $value) {
                        continue;
                    }
                    $others = array_diff_key($current, [$code => true]);
                    $matches = \count(array_intersect_assoc($others, $variant['axes']));
                    $score = [$matches, $variant['available'] ? 1 : 0, -$position];
                    if (null === $bestScore || $score > $bestScore) {
                        $target = $variant;
                        $bestScore = $score;
                    }
                }
                if (null === $target || null === $bestScore) {
                    continue;
                }
                $options[] = [
                    'value' => $value,
                    'selected' => ($current[$code] ?? null) === $value,
                    'available' => $target['available'],
                    'exact' => $bestScore[0] === \count(array_diff_key($current, [$code => true])),
                    'target' => $target['axes'],
                ];
            }
            if ([] !== $options) {
                $choices[] = ['code' => $code, 'options' => $options];
            }
        }

        return $choices;
    }
}
