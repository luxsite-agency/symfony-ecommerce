<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Audit;

use App\Core\Security\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Поточний актор журналу змін. Явний актор (runAs — пакети змін PR-3, консольні команди) має пріоритет; без нього
 * актор виводиться з запиту: користувач адмінки на хості admin_host → джерело admin_ui. Поза запитом (імпорт у воркері,
 * CLI без runAs) актора нема — зміни не журналюються.
 */
final class AdminAuditContext
{
    /** @var list<AuditActor|false> false — службова робота без журналу (unaudited) */
    private array $stack = [];

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requests,
        #[Autowire('%admin_host%')] private readonly string $adminHost,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function runAs(AuditActor $actor, callable $work): mixed
    {
        $this->stack[] = $actor;
        try {
            return $work();
        } finally {
            array_pop($this->stack);
        }
    }

    /**
     * Службові зміни, які не є дією користувача (порожні рядки налаштувань при першому відкритті сторінки — аудит UX A13):
     * у журнал не пишуться, навіть якщо запит іде від користувача адмінки.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function unaudited(callable $work): mixed
    {
        $this->stack[] = false;
        try {
            return $work();
        } finally {
            array_pop($this->stack);
        }
    }

    public function current(): ?AuditActor
    {
        if ([] !== $this->stack) {
            $explicit = $this->stack[array_key_last($this->stack)];

            return false === $explicit ? null : $explicit;
        }
        $request = $this->requests->getMainRequest();
        if (null === $request || 0 !== strcasecmp($request->getHost(), $this->adminHost)) {
            return null;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }
        $ip = $request->getClientIp();

        return AuditActor::user($user, AuditSource::AdminUi, null === $ip ? null : IpUtils::anonymize($ip));
    }
}
