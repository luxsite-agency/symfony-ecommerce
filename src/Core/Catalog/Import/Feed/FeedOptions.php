<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import\Feed;

/** Параметри розбору фіду: валюта за замовчуванням, локаль назв, роздільник CSV. */
final readonly class FeedOptions
{
    public function __construct(
        public string $currency = 'UAH',
        public string $locale = 'uk',
        public ?string $csvDelimiter = null,
        public bool $inStockOnly = false,
    ) {
    }
}
