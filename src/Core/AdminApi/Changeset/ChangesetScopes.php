<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Changeset;

use App\Core\AdminApi\ApiScope;
use App\Core\Changeset\Dto\Operation;

/**
 * Скоуп за складом пакета (E15 PR-4 §1), спільно для REST і MCP: цінові операції — prices:write, інші — content:write,
 * змішаний пакет — обидва. Невідомі op тут не судяться (422 дасть парсер). Тут же — лічильник цінових операцій для добового ліміту.
 */
final class ChangesetScopes
{
    public const array PRICE_OPS = Operation::PRICE_TYPES;

    /**
     * @param array<string, mixed> $data
     *
     * @return list<ApiScope> скоупи, яких вимагає пакет (порожньо — операцій нема, судитиме парсер)
     */
    public static function required(array $data): array
    {
        $prices = $content = false;
        foreach (self::operations($data) as $operation) {
            $op = $operation['op'] ?? null;
            if (\in_array($op, self::PRICE_OPS, true)) {
                $prices = true;
            } elseif (\is_string($op)) {
                $content = true;
            }
        }

        return array_values(array_filter([$prices ? ApiScope::PricesWrite : null, $content ? ApiScope::ContentWrite : null]));
    }

    /** @param array<string, mixed> $data */
    public static function priceOperations(array $data): int
    {
        $n = 0;
        foreach (self::operations($data) as $operation) {
            if (\in_array($operation['op'] ?? null, self::PRICE_OPS, true)) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private static function operations(array $data): array
    {
        $operations = $data['operations'] ?? [];
        $out = [];
        foreach (\is_array($operations) ? $operations : [] as $operation) {
            if (\is_array($operation)) {
                $out[] = $operation;
            }
        }

        return $out;
    }
}
