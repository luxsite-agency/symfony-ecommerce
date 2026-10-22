<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Message;

/** Щоночі зі Scheduler: неперевірені покупці старші за app.customer_unverified_days і прострочені токени листів. */
final readonly class PurgeUnverifiedCustomers
{
}
