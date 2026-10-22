<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Security;

use App\Core\Customer\Entity\Customer;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * user_checker firewall site: вимкнений покупець і покупець без підтвердженого email не входять ні паролем, ні
 * посиланням, ні remember_me. Вимкнення діє й на відкриті сесії — через Customer::isEqualTo() при оновленні токена.
 *
 * Перевірка — у checkPostAuth, тобто ПІСЛЯ пароля (рев'ю безпеки #45, п. 4а): у checkPreAuth окремі повідомлення
 * «вимкнено» / «не підтверджено» показувались на будь-який пароль і видавали, що акаунт з цією адресою існує. Тепер
 * їх бачить лише той, хто знає пароль; з неправильним паролем відповідь та сама, що й для відсутньої адреси.
 * Неперевірений покупець пароля не має (його задають на сторінці підтвердження), тож для нього вхід завжди дає
 * «невірні дані» ще на перевірці пароля. Покупець через Google (13.10.2026) пароля може не мати: достатньо будь-якого
 * способу входу (Customer::hasLoginMethod); підтверджений без жодного — заблокований lockOut() до нового пароля.
 */
final class CustomerChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof Customer) {
            return;
        }
        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('site.account.error.disabled');
        }
        if (!$user->isEmailVerified() || !$user->hasLoginMethod()) {
            throw new CustomUserMessageAccountStatusException('site.account.error.unverified');
        }
    }
}
