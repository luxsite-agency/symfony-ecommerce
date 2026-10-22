<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Auth;

use App\Core\AdminApi\Entity\AdminApiToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Ліміт спроб запису на токен (E15 PR-6, limiter admin_api_write, app.admin_api_write_rate_limit/хв): створення пакета
 * через REST POST /changesets і MCP propose_changes. Загальний ліміт запитів (120/хв) розрахований на читання; без окремого
 * контентні пакети (добова квота стосується лише цін) можна було б плодити тисячами на годину. Dry-run не рахується.
 */
final class ApiWriteLimiter
{
    public const string ERROR = 'too_many_requests';

    public function __construct(#[Autowire(service: 'limiter.admin_api_write')] private readonly RateLimiterFactoryInterface $limiter)
    {
    }

    /** @return int|null секунд до повтору (Retry-After); null — спроба прийнята */
    public function consume(AdminApiToken $token): ?int
    {
        $limit = $this->limiter->create((string) $token->getId())->consume();

        return $limit->isAccepted() ? null : max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
