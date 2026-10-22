<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Google;

use RuntimeException;

/**
 * Вхід чи прив'язка через Google відхилені правилами магазину (не протоколом): demo, admin, email_unverified, disabled,
 * already_linked, sub_taken. Код — у журнал і в ключ перекладу site.account.google.error.<reason>.
 */
final class GoogleSignInRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function messageKey(): string
    {
        return 'site.account.google.error.'.$this->reason;
    }
}
