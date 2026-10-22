<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Auth;

use App\Core\AdminApi\Entity\AdminApiToken;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\BadgeInterface;

/** Передає знайдений API-токен з authenticate() у createToken() — далі він живе атрибутом security-токена. */
final readonly class ApiTokenBadge implements BadgeInterface
{
    public function __construct(public AdminApiToken $token)
    {
    }

    public function isResolved(): bool
    {
        return true;
    }
}
