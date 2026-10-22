<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Exception;

use App\Core\Changeset\ChangesetStatus;

/** Пакет уже застосовано, відхилено або прострочено → 409 (прострочено — 410). */
final class ChangesetNotApplicable extends ChangesetException
{
    public function __construct(public readonly int $changesetId, public readonly ChangesetStatus $status)
    {
        parent::__construct(\sprintf('Пакет #%d у стані «%s»', $changesetId, $status->label()), ChangesetStatus::Expired === $status ? 410 : 409);
    }

    public function payload(): array
    {
        return ['error' => 'not_applicable', 'message' => $this->getMessage(), 'status' => $this->status->value];
    }
}
