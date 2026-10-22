<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Search;

use App\Core\Catalog\Search\ProductSearch;

/**
 * Рядок пошуку адмінки → слова для умов «кожне слово — в якомусь полі» (AND). Пробіли згорнуті, довжина обрізана
 * (ProductSearch::normalize); телефон, набраний з пробілами чи дужками («+380 (50) 111-22-33», «0412 345 678»), —
 * одне слово з цифр, а не кілька. Номер замовлення через дефіс («2610-000112») лишається як є.
 */
final class AdminSearchQuery
{
    public const int MAX_TERMS = 6;

    /** @return list<string> */
    public static function terms(string $raw): array
    {
        $q = ProductSearch::normalize($raw);
        if ('' === $q) {
            return [];
        }
        $q = (string) preg_replace_callback(
            '/\+?\(?\d+\)?(?:[\s\-]+\(?\d+\)?)+/u',
            static fn (array $m): string => preg_match('/[\s()]/u', $m[0]) && \strlen((string) preg_replace('/\D+/', '', $m[0])) >= 7
                ? (str_starts_with($m[0], '+') ? '+' : '').preg_replace('/\D+/', '', $m[0])
                : $m[0],
            $q,
        );
        $terms = array_values(array_unique(preg_split('/\s+/u', mb_strtolower($q), -1, \PREG_SPLIT_NO_EMPTY) ?: []));

        return \array_slice($terms, 0, self::MAX_TERMS);
    }

    /** Цифри слова, якщо воно схоже на телефон чи номер (лише цифри, +, пробіли, дужки, дефіси); інакше null. */
    public static function digits(string $term): ?string
    {
        if (!preg_match('/^\+?[\d\s()\-]+$/', $term)) {
            return null;
        }
        $digits = (string) preg_replace('/\D+/', '', $term);

        return '' === $digits ? null : $digits;
    }
}
