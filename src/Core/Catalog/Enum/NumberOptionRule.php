<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Enum;

/**
 * Формула числової опції (ADR Rev 9 Q2): per_unit = ставка × значення; above_threshold = ставка × max(0, значення − поріг)
 * (напр. понад стандартні 2000 мм висоти).
 */
enum NumberOptionRule: string
{
    case PerUnit = 'per_unit';
    case AboveThreshold = 'above_threshold';
}
