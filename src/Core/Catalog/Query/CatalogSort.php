<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

/** Сортування лістингу. «За запитом» (price_mode on_request) у цінових сортуваннях завжди в кінці. */
enum CatalogSort: string
{
    case PriceAsc = 'price_asc';
    case PriceDesc = 'price_desc';
    case Newest = 'newest';
}
