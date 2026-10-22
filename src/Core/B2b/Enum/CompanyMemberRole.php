<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\B2b\Enum;

/** Роль покупця в компанії (company_member). У v1 одна людина на компанію, власник; інші ролі з P2. */
enum CompanyMemberRole: string
{
    case Owner = 'owner';
    case Member = 'member';
}
