<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Plan;

/**
 * Diff однієї операції: ціль (тип, id, підпис) і зміни полів «було → стане». before/after — скаляри або масиви (JSON),
 * гроші — десяткові рядки, списки категорій — відсортовані id. Поле без фактичної зміни в diff не потрапляє.
 */
final class OperationDiff
{
    /** @var list<array{field: string, locale: string|null, before: mixed, after: mixed}> */
    private array $changes = [];

    public function __construct(
        public readonly int $index,
        public readonly string $op,
        public readonly string $targetType,
        public readonly ?int $targetId,
        public readonly ?string $targetLabel,
    ) {
    }

    public function change(string $field, mixed $before, mixed $after, ?string $locale = null): void
    {
        if ($before === $after) {
            return;
        }
        $this->changes[] = ['field' => $field, 'locale' => $locale, 'before' => $before, 'after' => $after];
    }

    public function isEmpty(): bool
    {
        return [] === $this->changes;
    }

    /** @return list<array{field: string, locale: string|null, before: mixed, after: mixed}> */
    public function changes(): array
    {
        return $this->changes;
    }

    /** @return array{index: int, op: string, target: array{type: string, id: int|null, label: string|null}, changes: list<array{field: string, locale: string|null, before: mixed, after: mixed}>} */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'op' => $this->op,
            'target' => ['type' => $this->targetType, 'id' => $this->targetId, 'label' => $this->targetLabel],
            'changes' => $this->changes,
        ];
    }
}
