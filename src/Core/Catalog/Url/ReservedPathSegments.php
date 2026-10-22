<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Url;

/**
 * Перші сегменти URL, зайняті статичними маршрутами сайту й службовими шляхами (cart, checkout, blog, media, en…):
 * слаг товару й кореневої категорії з ними не може збігатися, бо /{slug} і /{path}/ — catch-all. Реалізація — у Site
 * (з маршрутизатора), Core лише питає.
 */
interface ReservedPathSegments
{
    public function isReserved(string $segment): bool;

    /** @return list<string> */
    public function all(): array;
}
