<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import\Message;

/** Обробити один чанк staging запуску імпорту (Messenger async; ADR: чанки ≤ 50 с під воркер з --time-limit). */
final readonly class ProcessImportChunk
{
    public function __construct(public int $runId, public int $chunkNo)
    {
    }
}
