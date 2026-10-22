<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Security;

use App\Core\Changeset\Entity\Changeset;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Хто що може з пакетом змін (E15 PR-4, critic §3): бачити, застосувати й відхилити — автор пакета або ROLE_ADMIN;
 * відкотити — хто бачить застосований. Ризикові пакети не ескалюються (рішення власника 11.10, п. 4): той самий менеджер,
 * підтвердження окремо. Пакети без автора (користувача видалено) — лише адміністратор.
 * Стан для APPLY/REJECT тут не судиться (рев'ю C #77 M2): це справа аплаєра під FOR UPDATE, який на не-proposed кидає
 * ChangesetNotApplicable → flash «уже застосовано / прострочено», а не 403; кнопки ховає isProposed() у контролері.
 *
 * @extends Voter<string, Changeset>
 */
final class ChangesetVoter extends Voter
{
    public const string VIEW = 'CHANGESET_VIEW';
    public const string APPLY = 'CHANGESET_APPLY';
    public const string REJECT = 'CHANGESET_REJECT';
    public const string REVERT = 'CHANGESET_REVERT';

    public function __construct(private readonly RoleHierarchyInterface $roles)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Changeset && \in_array($attribute, [self::VIEW, self::APPLY, self::REJECT, self::REVERT], true);
    }

    /** @param Changeset $subject */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        $visible = $subject->isOwnedBy($user) || \in_array(UserRole::Admin->value, $this->roles->getReachableRoleNames($token->getRoleNames()), true);
        if (!$visible) {
            $vote?->addReason('Пакет бачить лише його автор або адміністратор');

            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            self::APPLY, self::REJECT => true,
            self::REVERT => $subject->getStatus()->value === 'applied',
            default => false,
        };
    }
}
