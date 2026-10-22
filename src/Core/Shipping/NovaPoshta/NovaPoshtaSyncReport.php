<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shipping\NovaPoshta;

/** Підсумок синхронізації довідників: скільки записів прийшло і скільки застарілих прибрано. */
final readonly class NovaPoshtaSyncReport
{
    public function __construct(public int $cities, public int $warehouses, public int $removedCities, public int $removedWarehouses, public float $seconds)
    {
    }
}
