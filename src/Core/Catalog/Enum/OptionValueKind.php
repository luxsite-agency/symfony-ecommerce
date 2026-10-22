<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Enum;

/** Вид значення опції (ADR Rev 6): націнка або компонент-варіант × кількість. */
enum OptionValueKind: string
{
    case Modifier = 'modifier';
    case Component = 'component';
}
