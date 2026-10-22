<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Crm\Exception;

use RuntimeException;

/** Вебхук не пройшов перевірку (токен, формат) — контролер відповідає 4xx, тіло не обробляється. */
final class WebhookRejectedException extends RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 400)
    {
        parent::__construct($message);
    }
}
