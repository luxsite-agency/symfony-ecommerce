<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Crm;

/** Що вміє конектор (ADR Rev 3): від цього залежить, чи чекати вебхук, чи лише звіряти за розкладом. */
final readonly class CrmCapabilities
{
    public function __construct(
        public bool $stockWebhook = false,
        public bool $orderWebhook = false,
        public bool $priceSync = false,
        public bool $deltaImport = false,
        public bool $pushOrder = false,
    ) {
    }
}
