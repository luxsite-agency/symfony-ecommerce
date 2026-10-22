<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Auth;

use App\Core\AdminApi\ApiScope;
use App\Core\AdminApi\Entity\AdminApiToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Атрибут `scope:<name>` (#[IsGranted('scope:prices:write')]): так лише коли запит прийшов з API-токеном, у токені є скоуп
 * **і** у власника є роль, що дає це в адмінці (role_hierarchy). Сесія адмінки чи відсутність токена → ні: REST/MCP —
 * тільки з токеном, а токен не може більше за людину.
 *
 * @extends Voter<string, mixed>
 */
final class ScopeVoter extends Voter
{
    public const string PREFIX = 'scope:';

    public function __construct(private readonly RoleHierarchyInterface $roles)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return str_starts_with($attribute, self::PREFIX);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $api = $token->hasAttribute(ApiTokenContext::ATTRIBUTE) ? $token->getAttribute(ApiTokenContext::ATTRIBUTE) : null;
        if (!$api instanceof AdminApiToken) {
            $vote?->addReason('Запит без API-токена');

            return false;
        }
        $scope = ApiScope::tryFrom(substr($attribute, \strlen(self::PREFIX)));
        if (null === $scope || !$api->hasScope($scope)) {
            $vote?->addReason('У токені нема скоупу '.$attribute);

            return false;
        }

        return \in_array($scope->requiredRole()->value, $this->roles->getReachableRoleNames($token->getRoleNames()), true);
    }
}
