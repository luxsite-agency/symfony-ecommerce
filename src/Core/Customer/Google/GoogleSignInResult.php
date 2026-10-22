<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Google;

use App\Core\Customer\Entity\Customer;

/** Підсумок входу через Google: покупець; створений щойно (sign_up) чи наявний (login); чи прив'язка відбулась у цьому вході. */
final readonly class GoogleSignInResult
{
    public function __construct(
        public Customer $customer,
        public bool $isNew,
        public bool $linkedNow,
        public bool $verifiedPlaceholder,
    ) {
    }
}
