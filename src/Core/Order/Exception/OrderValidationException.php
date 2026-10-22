<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Exception;

use InvalidArgumentException;
use Throwable;

/** Помилки оформлення для форми: поле → повідомлення (порожнє поле — загальна помилка). */
class OrderValidationException extends InvalidArgumentException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(implode('; ', $errors), $code, $previous);
    }
}
