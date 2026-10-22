<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Exception;

/** Пакет з ризиковими прапорцями без явного підтвердження менеджера (рішення власника 11.10, п. 4) → 422. */
final class RiskConfirmationRequired extends ChangesetException
{
    /** @param list<string> $riskFlags */
    public function __construct(public readonly array $riskFlags)
    {
        parent::__construct(\sprintf('Потрібне підтвердження ризикової зміни: %s', implode(', ', $riskFlags)), 422);
    }

    public function payload(): array
    {
        return ['error' => 'risk_confirmation_required', 'message' => $this->getMessage(), 'risk_flags' => $this->riskFlags];
    }
}
