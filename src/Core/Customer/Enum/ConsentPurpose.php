<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Enum;

/** Призначення згоди (консиліум 07.10, П2): обробка ПД для акаунта і маркетингові листи. SMS і умови — коли зʼявляться. */
enum ConsentPurpose: string
{
    case PdProcessing = 'pd_processing';
    case MarketingEmail = 'marketing_email';
}
