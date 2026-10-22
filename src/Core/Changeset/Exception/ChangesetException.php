<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Exception;

use RuntimeException;
use Throwable;

/** Базовий виняток пакетів змін: statusCode — підказка контролеру PR-4 (413/422/409/410), payload — тіло відповіді. */
abstract class ChangesetException extends RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** @return array<string, mixed> */
    abstract public function payload(): array;
}
