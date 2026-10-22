<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Review;

use RuntimeException;

/** Добовий ліміт нових відгуків покупця (app.reviews.daily_limit) вичерпано. */
final class ReviewLimitException extends RuntimeException
{
}
