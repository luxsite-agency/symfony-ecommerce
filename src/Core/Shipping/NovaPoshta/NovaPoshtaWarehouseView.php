<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shipping\NovaPoshta;

/** Рядок довідника відділень/поштоматів для select і снепшота. */
final readonly class NovaPoshtaWarehouseView
{
    public function __construct(public string $ref, public string $cityRef, public string $number, public string $name, public string $nameRu, public string $shortAddress, public string $type, public bool $active)
    {
    }

    public function label(string $locale): string
    {
        return 'ru' === $locale && '' !== $this->nameRu ? $this->nameRu : $this->name;
    }
}
