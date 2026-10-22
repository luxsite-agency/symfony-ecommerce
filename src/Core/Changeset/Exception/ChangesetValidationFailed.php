<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Exception;

use App\Core\Changeset\Plan\Issue;

/** Схема, resolve, expect, домен або контент не пройшли на dry-run: пакет не створено → 422 зі списком знахідок по операціях. */
final class ChangesetValidationFailed extends ChangesetException
{
    /** @param list<Issue> $issues */
    public function __construct(public readonly array $issues)
    {
        $first = $issues[0] ?? null;
        parent::__construct(null === $first ? 'Пакет не пройшов перевірку' : (null === $first->index ? $first->message : \sprintf('Операція %d: %s', $first->index, $first->message)), 422);
    }

    public function payload(): array
    {
        return ['error' => 'validation_failed', 'message' => $this->getMessage(), 'errors' => array_map(static fn (Issue $i): array => $i->toArray(), $this->issues)];
    }
}
