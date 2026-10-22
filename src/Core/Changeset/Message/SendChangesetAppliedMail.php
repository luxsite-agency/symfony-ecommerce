<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Message;

/** Лист відповідальним за ціни про застосований ціновий пакет (E15 PR-4) — через чергу, після commit. */
final readonly class SendChangesetAppliedMail
{
    public function __construct(public int $changesetId)
    {
    }
}
