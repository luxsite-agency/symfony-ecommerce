<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\FilterIndex;

use App\Core\Catalog\FilterIndex\Message\ReindexProducts;
use App\Core\Catalog\Query\CatalogCacheVersion;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Живе поза теки Message/, бо services.yaml виключає її з автореєстрації (там лише DTO повідомлень). */
#[AsMessageHandler]
final class ReindexProductsHandler
{
    public function __construct(private readonly FilterIndexer $indexer, private readonly CatalogCacheVersion $cacheVersion)
    {
    }

    public function __invoke(ReindexProducts $message): void
    {
        $this->indexer->reindexProducts($message->productIds);
        $this->cacheVersion->bump();
    }
}
