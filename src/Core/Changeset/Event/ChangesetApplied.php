<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Event;

/**
 * Пакет застосовано й транзакцію закомічено (E15 PR-4): слухачі — лист відповідальним за ціни (ChangesetMailListener).
 * Диспатчиться після commit, тож побічні ефекти слухачів не відкотяться разом із пакетом.
 */
final readonly class ChangesetApplied
{
    public function __construct(public int $changesetId, public ?int $appliedByUserId, public bool $hasPriceOperations)
    {
    }
}
