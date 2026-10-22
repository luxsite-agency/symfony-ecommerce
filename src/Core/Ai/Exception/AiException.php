<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Ai\Exception;

use RuntimeException;

/** Базовий виняток AI-шлюзу. Підкласи кажуть обробнику Messenger, чи є сенс повторювати. */
class AiException extends RuntimeException
{
    /** Повтор пізніше має шанс (429, 5xx, мережа); false — 401/402/бюджет/не налаштовано. */
    public function isRetryable(): bool
    {
        return false;
    }
}
