<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Translation;

use DomainException;
use Throwable;

/** DomainException з ключем перекладу для адмінки (TranslatableException). */
class TranslatableDomainException extends DomainException implements TranslatableException
{
    use TranslatableExceptionTrait;

    /** @param array<string, scalar|null> $parameters */
    public function __construct(string $message, string $key = '', array $parameters = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->setMessageKey($key, $parameters);
    }
}
