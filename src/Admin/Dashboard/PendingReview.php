<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Dashboard;

use DateTimeImmutable;

/** Рядок віджета «нові відгуки на модерації» — лише поля для таблиці, без гідрації ProductReview. */
final readonly class PendingReview
{
    public function __construct(
        public int $id,
        public string $productName,
        public int $rating,
        public string $customerName,
        public string $excerpt,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
