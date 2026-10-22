<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Auth;

use Symfony\Component\HttpFoundation\Request;

/**
 * Шляхи firewall admin_api (security.yaml: `^/(api/admin|mcp)(/|$)`) — одне місце для всіх слухачів E15 (ліміт на токен,
 * страж розміру тіла, журнал запитів). Правило nginx (docker/nginx/*.conf, `location ~ ^/(api/admin|mcp)(/|$)`) — те саме.
 */
final class ApiPaths
{
    public const string PATTERN = '#^/(api/admin|mcp)(/|$)#';

    public static function matches(Request $request): bool
    {
        return 1 === preg_match(self::PATTERN, $request->getPathInfo());
    }
}
