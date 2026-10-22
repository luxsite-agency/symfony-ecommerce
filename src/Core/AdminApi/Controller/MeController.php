<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Controller;

use App\Core\AdminApi\Auth\ApiTokenContext;
use App\Core\Security\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * GET /api/admin/v1/me — хто я за цим токеном: користувач, токен (без значення), скоупи. Перша перевірка після
 * `claude mcp add` чи curl; жодного скоупу не вимагає. Інші ресурси REST — PR-4, /mcp — PR-5.
 */
#[Route('/api/admin/v1', host: '%admin_host%', format: 'json', name: 'admin_api_')]
final class MeController extends AbstractController
{
    #[Route('/me', name: 'me', methods: ['GET'])]
    public function __invoke(#[CurrentUser] User $user, ApiTokenContext $context): JsonResponse
    {
        $token = $context->current() ?? throw new AccessDeniedException('Лише з API-токеном');

        return $this->json([
            'user' => ['id' => $user->getId(), 'name' => $user->getDisplayName(), 'role' => $user->getRole()->value],
            'token' => [
                'id' => $token->getId(),
                'name' => $token->getName(),
                'prefix' => $token->getPrefix(),
                'scopes' => $token->getScopeValues(),
                'created_at' => $token->getCreatedAt()->format(\DATE_ATOM),
                'expires_at' => $token->getExpiresAt()->format(\DATE_ATOM),
            ],
            'scopes' => $token->getScopeValues(),
        ]);
    }
}
