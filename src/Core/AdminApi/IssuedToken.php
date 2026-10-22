<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi;

use App\Core\AdminApi\Entity\AdminApiToken;
use SensitiveParameter;

/** Щойно виданий токен: сутність + значення відкритим текстом — показати один раз і забути (ніде не зберігається). */
final readonly class IssuedToken
{
    public function __construct(public AdminApiToken $token, #[SensitiveParameter] public string $plain)
    {
    }
}
