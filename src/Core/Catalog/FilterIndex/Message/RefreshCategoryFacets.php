<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\FilterIndex\Message;

/**
 * Перерахувати зведення фасетів змінених категорій (ADR Rev 11 п. 4). Без полів: обробник бере з черги
 * catalog_filter_category_dirty усі категорії, що відлежали затримку. Диспатчить CategoryFacetQueue з DelayStamp.
 */
final readonly class RefreshCategoryFacets
{
}
