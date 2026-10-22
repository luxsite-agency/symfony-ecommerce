<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Enum;

/** Одиниця продажу варіанта (ADR Rev 5). */
enum SalesUnit: string
{
    case Piece = 'pc';
    case Meter = 'm';
    case SquareMeter = 'm2';
    case Kilogram = 'kg';
    case Set = 'set';
}
