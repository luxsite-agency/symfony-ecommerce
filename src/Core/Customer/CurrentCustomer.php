<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer;

/**
 * Хто зараз покупець для сервісів Core, яким треба знати власника (кошик): Site-шар реалізує через security-токен
 * firewall site (CustomerContext), CLI й Messenger — ніхто (null). Core токен не читає.
 */
interface CurrentCustomer
{
    public function customerId(): ?int;
}
