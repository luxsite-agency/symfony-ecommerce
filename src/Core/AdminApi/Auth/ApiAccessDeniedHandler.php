<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Auth;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * 403 на firewall admin_api: токен чинний, але без скоупу чи ролі. Формат зі спеки MCP/RFC 6750:
 * WWW-Authenticate: Bearer error="insufficient_scope", scope="prices:write" — клієнт бачить, якого скоупу бракує.
 */
final class ApiAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        $scopes = [];
        foreach ($accessDeniedException->getAttributes() as $attribute) {
            if (\is_string($attribute) && str_starts_with($attribute, ScopeVoter::PREFIX)) {
                $scopes[] = substr($attribute, \strlen(ScopeVoter::PREFIX));
            }
        }
        if ([] === $scopes) {
            return new JsonResponse(['error' => 'forbidden', 'error_description' => 'Недостатньо прав'], Response::HTTP_FORBIDDEN, ['WWW-Authenticate' => \sprintf('Bearer realm="%s", error="insufficient_scope"', ApiTokenAuthenticator::REALM)]);
        }
        $scope = implode(' ', $scopes);

        return new JsonResponse(
            ['error' => 'insufficient_scope', 'scope' => $scope, 'error_description' => \sprintf('Токену бракує скоупу %s або власнику — ролі для нього', $scope)],
            Response::HTTP_FORBIDDEN,
            ['WWW-Authenticate' => \sprintf('Bearer realm="%s", error="insufficient_scope", scope="%s"', ApiTokenAuthenticator::REALM, $scope)],
        );
    }
}
