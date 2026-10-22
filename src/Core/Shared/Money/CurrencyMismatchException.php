<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Money;

use LogicException;

final class CurrencyMismatchException extends LogicException
{
    public function __construct(string $expected, string $actual)
    {
        parent::__construct(\sprintf('Операція над різними валютами: %s і %s', $expected, $actual));
    }
}
