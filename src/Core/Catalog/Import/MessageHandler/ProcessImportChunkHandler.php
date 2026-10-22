<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import\MessageHandler;

use App\Core\Catalog\Import\Message\ProcessImportChunk;
use App\Core\Catalog\Import\ProductImporter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessImportChunkHandler
{
    public function __construct(private readonly ProductImporter $importer)
    {
    }

    public function __invoke(ProcessImportChunk $message): void
    {
        $this->importer->processChunk($message->runId, $message->chunkNo);
    }
}
