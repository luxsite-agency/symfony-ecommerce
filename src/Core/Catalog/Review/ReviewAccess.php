<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Review;

/** Чи може відвідувач писати відгук: рішення ProductReviews::access() для форми на картці й контролера кабінету. */
enum ReviewAccess: string
{
    /** Покупець увійшов і може писати. */
    case Allowed = 'allowed';
    /** app.reviews.enabled = false: ні форми, ні списку. */
    case Disabled = 'disabled';
    /** Гість: спершу вхід (звичайний або через Google). */
    case LoginRequired = 'login';
    /** app.reviews.require_google = true, покупець не прив'язаний до Google. */
    case GoogleRequired = 'google';
}
