<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo;

/**
 * Адреса сутності змінилась: слаг товару, сторінки, статті або повний шлях категорії (без мовного префікса й слешів —
 * як у БД). old = null — адреса з'явилась (нова сутність чи переклад): правило з неї, якщо було, знімається. URL з
 * цього будує реалізація SlugChangeRecorder (вона знає маршрути сайту).
 */
final readonly class SlugChange
{
    public const string PRODUCT = 'product';
    public const string CATEGORY = 'category';
    public const string PAGE = 'page';
    public const string POST = 'post';

    /** @param self::PRODUCT|self::CATEGORY|self::PAGE|self::POST $type */
    public function __construct(
        public string $type,
        public string $locale,
        public ?string $old,
        public string $new,
    ) {
    }
}
