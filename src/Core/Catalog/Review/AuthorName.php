<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Review;

/**
 * Підпис автора відгуку на сайті: «Ім'я П.» — перше слово повністю, друге ініціалом; без email. Одне слово — як є;
 * порожнє ім'я (стертий акаунт) — порожній рядок, шаблон підставляє «Покупець».
 */
final class AuthorName
{
    public static function short(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        if ([] === $words) {
            return '';
        }
        $first = array_shift($words);
        $second = $words[0] ?? null;
        if (null === $second) {
            return $first;
        }
        $initial = mb_substr($second, 0, 1);
        // дефісні прізвища: Петренко-Іваненко → П.-І.
        if (str_contains($second, '-') && 1 === preg_match('/^(.)[^-]*-(.)/u', $second, $m)) {
            $initial = $m[1].'.-'.$m[2];
        }

        return $first.' '.mb_strtoupper($initial).'.';
    }
}
