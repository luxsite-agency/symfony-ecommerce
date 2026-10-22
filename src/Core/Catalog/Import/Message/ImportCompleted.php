<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import\Message;

/**
 * Імпорт завершено (ProductImporter::finalize): каталог і ціни/залишки змінились. Підписники — похідні артефакти поза
 * Core (Google Merchant feed, Site\Feed\MessageHandler); Core про них не знає.
 */
final readonly class ImportCompleted
{
    public function __construct(public int $runId)
    {
    }
}
