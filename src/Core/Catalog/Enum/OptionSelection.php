<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Enum;

/** Скільки значень групи опцій можна обрати. */
enum OptionSelection: string
{
    case Single = 'single';
    case Multiple = 'multiple';
}
