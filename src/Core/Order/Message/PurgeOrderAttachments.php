<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Message;

/** Щоночі зі Scheduler: стерти файли вкладень, старші за app.order_attachment_retention_days (рядок лишається з purged_at). */
final readonly class PurgeOrderAttachments
{
}
