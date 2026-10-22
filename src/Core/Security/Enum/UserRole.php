<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Security\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Ролі адмінки (ADR D10): ROLE_ADMIN успадковує ROLE_MANAGER через role_hierarchy. */
enum UserRole: string implements TranslatableInterface
{
    case Admin = 'ROLE_ADMIN';
    case Manager = 'ROLE_MANAGER';

    /** Підпис ролі в адмінці мовою адмінки (enum.user_role; ключ — значення без ROLE_, у нижньому регістрі). */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.user_role.'.strtolower(substr($this->value, 5)), [], 'admin', $locale);
    }
}
