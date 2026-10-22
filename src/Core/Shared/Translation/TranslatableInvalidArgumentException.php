<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Translation;

use InvalidArgumentException;
use Throwable;

/** InvalidArgumentException з ключем перекладу для адмінки (TranslatableException): невалідне введення в сеттері сутності. */
class TranslatableInvalidArgumentException extends InvalidArgumentException implements TranslatableException
{
    use TranslatableExceptionTrait;

    /** @param array<string, scalar|null> $parameters */
    public function __construct(string $message, string $key = '', array $parameters = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->setMessageKey($key, $parameters);
    }
}
