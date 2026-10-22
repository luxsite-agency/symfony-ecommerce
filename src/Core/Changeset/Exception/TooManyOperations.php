<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Exception;

/** Понад app.ai_changeset_max_ops операцій у пакеті (рішення власника 11.10, п. 5) → 413. */
final class TooManyOperations extends ChangesetException
{
    public function __construct(public readonly int $count, public readonly int $max)
    {
        parent::__construct(\sprintf('У пакеті %d операцій, дозволено не більше %d', $count, $max), 413);
    }

    public function payload(): array
    {
        return ['error' => 'too_many_operations', 'message' => $this->getMessage(), 'count' => $this->count, 'max' => $this->max];
    }
}
