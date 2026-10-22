<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import\MessageHandler;

use App\Core\Catalog\Import\Media\MediaImporter;
use App\Core\Catalog\Import\Message\ImportProductMedia;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Помилка окремого URL не валить повідомлення (лог + пропуск), тож ретраї Messenger — лише для збою БД. */
#[AsMessageHandler]
final class ImportProductMediaHandler
{
    public function __construct(private readonly MediaImporter $importer)
    {
    }

    public function __invoke(ImportProductMedia $message): void
    {
        $this->importer->importForProduct($message->productId, $message->urls);
    }
}
