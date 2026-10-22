<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Messenger\Message;

/** Щоночі зі Scheduler: повідомлення в черзі failed старші за app.messenger_failed_days видаляються. */
final readonly class PruneFailedMessages
{
}
