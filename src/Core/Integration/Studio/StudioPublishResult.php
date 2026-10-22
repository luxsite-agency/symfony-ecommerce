<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Studio;

/** Результат upsert статті: id запису блогу (спільний для мов), абсолютний URL сторінки цією мовою, попередження. */
final readonly class StudioPublishResult
{
    /** @param list<string> $warnings напр. обкладинку не завантажено — стаття все одно опублікована */
    public function __construct(
        public int $postId,
        public string $url,
        public bool $created,
        public array $warnings = [],
    ) {
    }
}
