<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Changeset;

use App\Core\Changeset\Validate\AuditLookup;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Добовий ліміт цінових операцій на токен (app.ai_price_ops_per_day; 0 = без межі), спільний для REST POST /changesets
 * і MCP propose_changes: «застосовано за добу + у чинних proposed пакетах цього токена» (рев'ю C #77 M1 — інакше серія
 * proposed-пакетів до першого apply обходить ліміт). Перевищення — тіло 429 price_ops_limit з used/applied/pending/requested.
 */
final class PriceOpsQuota
{
    public const string ERROR = 'price_ops_limit';
    /** Квота добова — повторити має сенс не раніше ніж за годину. */
    public const int RETRY_AFTER = 3600;

    public function __construct(
        private readonly AuditLookup $audit,
        #[Autowire('%app.ai_price_ops_per_day%')] private readonly int $perDay,
    ) {
    }

    /**
     * @param array<string, mixed> $data тіло пакета {title, operations}
     *
     * @return array{error: string, message: string, limit: int, used: int, applied: int, pending: int, requested: int}|null null — у межах квоти
     */
    public function exceeded(array $data, int $tokenId): ?array
    {
        if ($this->perDay <= 0) {
            return null;
        }
        $inRequest = ChangesetScopes::priceOperations($data);
        if (0 === $inRequest) {
            return null;
        }
        $applied = $this->audit->priceOperationsToday($tokenId);
        $pending = $this->audit->pendingPriceOperations($tokenId);
        $used = $applied + $pending;
        if ($used + $inRequest <= $this->perDay) {
            return null;
        }

        return [
            'error' => self::ERROR,
            'message' => \sprintf('Ліміт цінових операцій на токен за добу: %d; за добу вже застосовано %d і чекає в запропонованих пакетах %d, у цьому пакеті %d', $this->perDay, $applied, $pending, $inRequest),
            'limit' => $this->perDay,
            'used' => $used,
            'applied' => $applied,
            'pending' => $pending,
            'requested' => $inRequest,
        ];
    }
}
