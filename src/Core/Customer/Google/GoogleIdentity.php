<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Google;

/** Перевірені claims id_token Google: sub — стабільний ідентифікатор акаунта, email_verified — чи Google підтвердив адресу. */
final readonly class GoogleIdentity
{
    public function __construct(
        public string $sub,
        public string $email,
        public bool $emailVerified,
        public ?string $name,
    ) {
    }
}
