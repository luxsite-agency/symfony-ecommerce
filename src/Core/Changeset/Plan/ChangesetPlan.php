<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Plan;

/**
 * Результат dry-run пакета: нормалізовані операції, diff, знахідки. hasErrors() — пакет не створюється (422) чи не
 * застосовується (409). riskFlags — унікальні коди ризиків, потрібне підтвердження менеджера при застосуванні.
 */
final class ChangesetPlan
{
    /** @var list<array<string, mixed>> */
    private array $operations = [];
    /** @var list<OperationDiff> */
    private array $diffs = [];
    /** @var list<Issue> */
    private array $issues = [];
    private int $priceOperations = 0;

    public function __construct(public readonly string $title)
    {
    }

    /** @param array<string, mixed> $operation */
    public function addOperation(array $operation, bool $isPrice): void
    {
        $this->operations[] = $operation;
        if ($isPrice) {
            ++$this->priceOperations;
        }
    }

    public function addDiff(OperationDiff $diff): void
    {
        $this->diffs[] = $diff;
    }

    public function add(Issue $issue): void
    {
        $this->issues[] = $issue;
    }

    /** @return list<array<string, mixed>> */
    public function operations(): array
    {
        return $this->operations;
    }

    /** @return list<OperationDiff> */
    public function diffs(): array
    {
        return $this->diffs;
    }

    public function priceOperations(): int
    {
        return $this->priceOperations;
    }

    /** @return list<Issue> */
    public function issues(): array
    {
        return $this->issues;
    }

    /** @return list<Issue> */
    public function errors(): array
    {
        return $this->ofKind(Issue::ERROR);
    }

    /** @return list<Issue> */
    public function warnings(): array
    {
        return $this->ofKind(Issue::WARNING);
    }

    /** @return list<Issue> */
    public function risks(): array
    {
        return $this->ofKind(Issue::RISK);
    }

    public function hasErrors(): bool
    {
        return [] !== $this->errors();
    }

    /** @return list<string> відсортовані унікальні коди ризиків */
    public function riskFlags(): array
    {
        $flags = array_values(array_unique(array_map(static fn (Issue $i): string => $i->code, $this->risks())));
        sort($flags);

        return $flags;
    }

    /** @return list<array<string, mixed>> */
    public function diffArray(): array
    {
        return array_map(static fn (OperationDiff $d): array => $d->toArray(), $this->diffs);
    }

    /** @return list<array<string, mixed>> попередження і ризики (для збереження в пакеті); i18n — ключ тексту для адмінки */
    public function warningsArray(): array
    {
        return array_map(static fn (Issue $i): array => $i->toArray() + ['kind' => $i->kind] + (null === $i->translatable || '' === $i->translatable->key ? [] : ['i18n' => $i->translatable->toArray()]), array_merge($this->warnings(), $this->risks()));
    }

    /** @return array<string, mixed> подання для API/CLI */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'operations' => $this->operations,
            'diff' => $this->diffArray(),
            'errors' => array_map(static fn (Issue $i): array => $i->toArray(), $this->errors()),
            'warnings' => array_map(static fn (Issue $i): array => $i->toArray(), $this->warnings()),
            'risk_flags' => $this->riskFlags(),
            'risks' => array_map(static fn (Issue $i): array => $i->toArray(), $this->risks()),
        ];
    }

    /** @return list<Issue> */
    private function ofKind(string $kind): array
    {
        return array_values(array_filter($this->issues, static fn (Issue $i): bool => $i->kind === $kind));
    }
}
