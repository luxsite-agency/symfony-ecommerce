<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Search;

/**
 * Запит, набраний не в тій розкладці: «ghjlernb» → «продукти», «руддщ» → «hello». Розкладки — стандартні Windows/macOS:
 * англійська QWERTY ↔ українська (і, ї, є, ґ, ') та російська ЙЦУКЕН (ы, ъ, э, ё). Регістр не важливий — пошук без регістру.
 */
final class KeyboardLayout
{
    private const string LATIN = "qwertyuiop[]asdfghjkl;'zxcvbnm,.`";
    private const string UK = 'йцукенгшщзхїфівапролджєячсмитьбю\'';
    private const string RU = 'йцукенгшщзхъфывапролджэячсмитьбюё';

    /**
     * Варіанти запиту в інших розкладках, без повторів і без самого запиту: латиниця → [uk, ru], кирилиця → [латиниця].
     * Змішаний запит або без літер — [].
     *
     * @return list<string>
     */
    public static function alternatives(string $query): array
    {
        $q = mb_strtolower($query);
        $latin = (bool) preg_match('/[a-z]/', $q);
        $cyrillic = (bool) preg_match('/\p{Cyrillic}/u', $q);
        if ($latin === $cyrillic) {
            return [];
        }
        $candidates = $latin
            ? [self::convert($q, self::LATIN, self::UK), self::convert($q, self::LATIN, self::RU)]
            : [self::convert($q, self::UK, self::LATIN), self::convert($q, self::RU, self::LATIN)];

        // лише повністю перекладені варіанти: «фызшкшт» в українській розкладці дав би «aыpirin» (ы на ній нема)
        $script = $latin ? '/[a-z]/' : '/\p{Cyrillic}/u';

        return array_values(array_unique(array_filter($candidates, static fn (string $c): bool => $c !== $q && !preg_match($script, $c))));
    }

    private static function convert(string $text, string $from, string $to): string
    {
        return strtr($text, array_combine(mb_str_split($from), mb_str_split($to)));
    }
}
