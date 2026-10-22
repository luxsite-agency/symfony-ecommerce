<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Analytics;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Постачальник HTML для трьох точок layout: <head>, одразу після <body>, перед </body>. Тегований сервіс —
 * магазин клієнта додає свої теги новим класом у Store/** без правки Core. Вищий пріоритет — раніше у виводі.
 * Повертає готовий HTML (виводиться сирим) або порожній рядок.
 */
#[AutoconfigureTag('app.analytics_tag_provider')]
interface AnalyticsTagProviderInterface
{
    public static function getPriority(): int;

    public function head(TagContext $context): string;

    public function bodyStart(TagContext $context): string;

    public function bodyEnd(TagContext $context): string;
}
