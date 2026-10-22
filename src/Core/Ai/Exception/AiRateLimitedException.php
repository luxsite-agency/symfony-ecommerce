<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Ai\Exception;

/** 429 від провайдера: retryAfter — секунди з Retry-After, якщо був. */
final class AiRateLimitedException extends AiException
{
    public function __construct(string $message, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message, 429);
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
