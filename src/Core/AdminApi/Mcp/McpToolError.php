<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Mcp;

use RuntimeException;

/**
 * Помилка інструмента MCP з HTTP-подібним статусом (як ApiProblem у service-engine): контролер віддає її моделі як результат
 * з isError = true і текстом (не як HTTP-помилку — та до моделі не дійде). payload — структуровані подробиці (errors пакета тощо).
 *
 * @phpstan-type Payload array<string, mixed>
 */
final class McpToolError extends RuntimeException
{
    /** @param array<string, mixed> $payload */
    public function __construct(string $message, public readonly int $status = 400, public readonly array $payload = [])
    {
        parent::__construct($message);
    }
}
