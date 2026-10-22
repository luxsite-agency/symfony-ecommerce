<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Studio;

use RuntimeException;

/** Публікацію від зовнішньої контент-панелі відхилено: 422 (невалідні поля) або 409 (слаг зайнятий іншим записом). */
final class StudioPayloadException extends RuntimeException
{
    /** @param array<string, string> $fields поле payload → причина */
    public function __construct(string $message, public readonly int $statusCode = 422, public readonly array $fields = [])
    {
        parent::__construct($message);
    }
}
