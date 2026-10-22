<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

/**
 * Картка в лістингу: id товару і мінімальна ціна серед варіантів, що пройшли фільтр (розмір 42 → ціна саме
 * варіантів 42). priceMode — код із read-index: 0 fixed, 1 from, 2 on_request (ціну не показувати).
 */
final readonly class CatalogHit
{
    public const int PRICE_FIXED = 0;
    public const int PRICE_FROM = 1;
    public const int PRICE_ON_REQUEST = 2;

    public function __construct(public int $productId, public int $minPriceMinor, public int $priceMode)
    {
    }

    public function isOnRequest(): bool
    {
        return self::PRICE_ON_REQUEST === $this->priceMode;
    }
}
