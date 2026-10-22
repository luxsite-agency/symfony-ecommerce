<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset;

use App\Core\Audit\AuditSource;
use App\Core\Security\Entity\User;

/**
 * Хто пропонує пакет: id і ім'я користувача адмінки (не сутність — після dry-run EntityManager очищається), токен API (PR-2),
 * джерело (ai_api для REST/MCP, cli для команди, ai_assist з PR-7), анонімізований IP для журналу.
 */
final readonly class ChangesetAuthor
{
    public function __construct(
        public int $userId,
        public string $name,
        public AuditSource $source,
        public ?int $tokenId = null,
        public ?string $ip = null,
    ) {
    }

    public static function of(User $user, AuditSource $source, ?int $tokenId = null, ?string $ip = null): self
    {
        return new self((int) $user->getId(), $user->getDisplayName(), $source, $tokenId, $ip);
    }
}
