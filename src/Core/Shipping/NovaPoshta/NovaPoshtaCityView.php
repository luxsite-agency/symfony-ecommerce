<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shipping\NovaPoshta;

/** Рядок довідника міст для автодоповнення і снепшота. */
final readonly class NovaPoshtaCityView
{
    public function __construct(public string $ref, public string $name, public string $nameRu, public string $area, public string $settlementType)
    {
    }

    public function name(string $locale): string
    {
        return 'ru' === $locale && '' !== $this->nameRu ? $this->nameRu : $this->name;
    }

    /** «Бориспіль, Київська обл.» — щоб розрізняти однойменні населені пункти у списку. */
    public function label(string $locale): string
    {
        $name = $this->name($locale);

        return '' === $this->area || $this->area === $name ? $name : \sprintf('%s, %s обл.', $name, $this->area);
    }
}
