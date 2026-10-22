<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Exception;

use RuntimeException;

final class OutOfStockException extends RuntimeException
{
    public function __construct(public readonly string $sku, public readonly string $quantity)
    {
        parent::__construct(\sprintf('Недостатньо залишку для %s (потрібно %s)', $sku, $quantity));
    }
}
