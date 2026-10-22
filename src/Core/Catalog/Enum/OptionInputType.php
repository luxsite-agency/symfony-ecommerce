<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Enum;

/** Як обирається група опцій (ADR Rev 9 Q2): значення зі списку або число в межах (довжина, кількість петель). */
enum OptionInputType: string
{
    case Select = 'select';
    case Number = 'number';
}
