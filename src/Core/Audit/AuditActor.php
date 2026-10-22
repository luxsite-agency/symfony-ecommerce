<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Audit;

use App\Core\Security\Entity\User;

/**
 * Хто й звідки робить зміну. Без актора слухач нічого не пише: імпорт, воркер, сайт — не журналюються
 * (у них своя звітність). IP — уже анонімізований (IpUtils::anonymize), у журналі повного IP не буває.
 */
final readonly class AuditActor
{
    public function __construct(
        public AuditSource $source,
        public ?int $userId = null,
        public ?string $actor = null,
        public ?int $tokenId = null,
        public ?int $changesetId = null,
        public ?string $ip = null,
    ) {
    }

    public static function user(User $user, AuditSource $source, ?string $ip = null, ?int $tokenId = null, ?int $changesetId = null): self
    {
        return new self($source, $user->getId(), $user->getDisplayName(), $tokenId, $changesetId, $ip);
    }

    public static function system(AuditSource $source, string $actor): self
    {
        return new self($source, null, $actor);
    }
}
