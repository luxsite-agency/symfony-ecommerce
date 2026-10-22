<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b;

use App\Core\Shared\Translation\TranslatableException;
use App\Core\Shared\Translation\TranslatableExceptionTrait;
use RuntimeException;
use Throwable;

/**
 * Відмова сервісу заявок з ключем перекладу для сайту (site.account.wholesale.error.*) чи текстом для адмінки.
 * Для адмінки — ще й ключ error.wholesale_application.* (TranslatableException); getMessage() лишається як був.
 */
final class WholesaleApplicationException extends RuntimeException implements TranslatableException
{
    use TranslatableExceptionTrait;

    /** @param array<string, scalar|null> $parameters */
    public function __construct(string $message, string $key = '', array $parameters = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->setMessageKey($key, $parameters);
    }
}
