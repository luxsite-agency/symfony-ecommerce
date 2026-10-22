<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Crm;

/** Результат pushOrder: ідентифікатор замовлення в CRM (null — CRM нема або не приймає замовлення). */
final readonly class CrmOrderRef
{
    private function __construct(public ?string $externalOrderId, public string $system)
    {
    }

    public static function of(string $system, string $externalOrderId): self
    {
        return new self($externalOrderId, $system);
    }

    public static function none(string $system): self
    {
        return new self(null, $system);
    }
}
