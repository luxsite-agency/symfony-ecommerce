<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Validate;

use App\Core\Changeset\Plan\Issue;
use DateTimeImmutable;

/**
 * До помилок conflict (expect не зійшовся) дописує з журналу #68, хто й коли змінив поле після $since — при застосуванні
 * (після створення пакета) і при відкаті (після застосування). Цілі операцій — {index: {type, id, locale}}.
 */
final class ConflictBlame
{
    public function __construct(private readonly AuditLookup $lookup)
    {
    }

    /**
     * @param list<Issue>                                                        $issues
     * @param array<int, array{type: string, id: int|null, locale: string|null}> $targets
     *
     * @return list<Issue>
     */
    public function enrich(array $issues, array $targets, DateTimeImmutable $since): array
    {
        $out = [];
        foreach ($issues as $issue) {
            $target = null === $issue->index ? null : ($targets[$issue->index] ?? null);
            if ('conflict' !== $issue->code || null === $target || null === $target['id']) {
                $out[] = $issue;
                continue;
            }
            $last = $this->lookup->lastChange($target['type'], $target['id'], self::auditField($target['type'], (string) ($issue->details['field'] ?? '')), $target['locale'], $since);
            if (null === $last) {
                $out[] = $issue;
                continue;
            }
            $out[] = Issue::error($issue->index, $issue->code, IssueMessage::of('changeset.issue.blame', ['message' => $issue->text(), 'who' => $last['changed_by'] ?? new IssueMessage((string) $last['source'], 'enum.audit_source.'.$last['source']), 'when' => (string) $last['changed_at']], \sprintf('%s (змінив %s %s)', $issue->message, $last['changed_by'] ?? $last['source'], $last['changed_at'])), $issue->details + $last);
        }

        return $out;
    }

    /**
     * Цілі з diff пакета (index → type/id/locale).
     *
     * @param list<array<string, mixed>> $diff
     *
     * @return array<int, array{type: string, id: int|null, locale: string|null}>
     */
    public static function targetsFromDiff(array $diff): array
    {
        $targets = [];
        foreach ($diff as $entry) {
            $locale = null;
            foreach ($entry['changes'] ?? [] as $change) {
                $locale = $change['locale'] ?? null;
            }
            $targets[(int) $entry['index']] = ['type' => (string) $entry['target']['type'], 'id' => isset($entry['target']['id']) ? (int) $entry['target']['id'] : null, 'locale' => \is_string($locale) ? $locale : null];
        }

        return $targets;
    }

    /** Ім'я поля в журналі (ORM) за ключем операції */
    private static function auditField(string $type, string $key): ?string
    {
        return match ([$type, $key]) {
            ['product_variant', 'price'], ['price_tier', 'price'] => 'priceMinor',
            ['product_variant', 'old_price'] => 'oldPriceMinor',
            ['product', 'categories'] => 'categories',
            ['product', 'main'] => 'mainCategory',
            default => '' === $key ? null : lcfirst(str_replace('_', '', ucwords($key, '_'))),
        };
    }
}
