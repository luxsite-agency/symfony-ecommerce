<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Enum;

/** Націнка modifier-опції: сума в мінорних одиницях або відсоток у базисних пунктах. */
enum ModifierType: string
{
    case Absolute = 'absolute';
    case Percent = 'percent';
}
