<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Exception;

use App\Core\Changeset\Plan\Issue;
use Throwable;

/**
 * При застосуванні поточні значення не збігаються з expect (хтось змінив між dry-run і apply), або з'явились нові ризикові
 * прапорці → 409. details кожної знахідки містять current, а коли є в журналі — changed_by/changed_at.
 */
final class ChangesetConflict extends ChangesetException
{
    /** @param list<Issue> $issues */
    public function __construct(public readonly array $issues, ?Throwable $previous = null)
    {
        $first = $issues[0] ?? null;
        parent::__construct(match (true) {
            null === $first => 'Дані змінились після створення пакета',
            null === $first->index => $first->message,
            default => \sprintf('Операція %d: %s', $first->index, $first->message),
        }, 409, $previous);
    }

    public function payload(): array
    {
        return ['error' => 'conflict', 'message' => $this->getMessage(), 'conflicts' => array_map(static fn (Issue $i): array => $i->toArray(), $this->issues)];
    }
}
