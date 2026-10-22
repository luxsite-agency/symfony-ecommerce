<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Theme;

use RuntimeException;

/** Відмова живого дизайну: HTTP-статус і код зі словника API (validation_failed, not_found, payload_too_large…). */
final class ThemeException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, public readonly int $status = 422, public readonly string $error = 'validation_failed', public readonly array $details = [])
    {
        parent::__construct($message);
    }

    /** @return array<string, mixed> тіло відповіді REST і structuredContent MCP */
    public function payload(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()] + $this->details;
    }
}
