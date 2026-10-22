<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

/**
 * Режим лічильників фасетів для одного розрахунку (ADR Rev 11 п. 5–6):
 * - enabled = false — лічильників нема ніде: значення з зведення категорії без цифри, дорогі запити не виконуються;
 * - maxSet — поріг N: база фасета (оцінка зверху) більша → цей фасет без цифр;
 * - degraded — спрацював rate limit: без лічильників, і діапазони чисел/ціни беруться з зведення (жодного запиту фасетів).
 */
final readonly class FacetCounting
{
    public function __construct(public bool $enabled, public int $maxSet, public bool $degraded = false)
    {
    }

    /** Точні лічильники за будь-якого розміру бази: перерахунок зведення, тести. */
    public static function exact(): self
    {
        return new self(true, \PHP_INT_MAX);
    }

    public function degrade(): self
    {
        return new self(false, $this->maxSet, true);
    }

    public function counts(): bool
    {
        return $this->enabled && !$this->degraded;
    }

    /** Частина ключа кешу: різні режими дають різні фасети. */
    public function key(): string
    {
        return $this->counts() ? 'n'.$this->maxSet : 'off';
    }
}
