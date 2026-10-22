<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import\Message;

/** Завантажити зображення картки з фіду (MediaImporter) — фоново, щоб повільні сервери фото не гальмували чанк імпорту. */
final readonly class ImportProductMedia
{
    /** @param list<string> $urls */
    public function __construct(public int $productId, public array $urls)
    {
    }
}
