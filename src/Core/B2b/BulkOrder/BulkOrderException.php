<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\BulkOrder;

use RuntimeException;

/** Список не прийнято цілком: завеликий файл, забагато рядків, порожньо. Код — ключ повідомлення для сайту. */
final class BulkOrderException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Список не прийнято: '.$reason);
    }
}
