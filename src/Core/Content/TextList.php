<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content;

/**
 * Списки рядків і FAQ у JSON-полях контенту (вантажі й галузі рішення, переваги варіанта) і їхній текстовий вигляд для адмінки:
 * список — рядок на пункт; FAQ — блоки через порожній рядок, перший рядок блоку — питання, решта — відповідь.
 */
final class TextList
{
    public const int MAX_ITEMS = 30;
    public const int MAX_LENGTH = 255;

    /**
     * Без порожніх і дублікатів, обрізані пробіли, не більше MAX_ITEMS пунктів по MAX_LENGTH символів.
     *
     * @param iterable<mixed> $items
     *
     * @return list<string>
     */
    public static function clean(iterable $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $item = \is_scalar($item) ? trim((string) $item) : '';
            if ('' !== $item && !\in_array($item, $out, true)) {
                $out[] = mb_substr($item, 0, self::MAX_LENGTH);
            }
        }

        return \array_slice($out, 0, self::MAX_ITEMS);
    }

    /** @return list<string> */
    public static function fromText(?string $text): array
    {
        return self::clean(preg_split('/\R/u', (string) $text) ?: []);
    }

    /** @param list<string> $items */
    public static function toText(array $items): string
    {
        return implode("\n", $items);
    }

    /** @return list<array{q: string, a: string}> */
    public static function faqFromText(?string $text): array
    {
        $faq = [];
        foreach (preg_split('/\R\s*\R/u', trim((string) $text)) ?: [] as $block) {
            $lines = preg_split('/\R/u', trim($block), 2) ?: [];
            $q = trim($lines[0] ?? '');
            $a = trim($lines[1] ?? '');
            if ('' !== $q && '' !== $a) {
                $faq[] = ['q' => mb_substr($q, 0, 500), 'a' => $a];
            }
        }

        return \array_slice($faq, 0, self::MAX_ITEMS);
    }

    /** @param list<array{q: string, a: string}> $faq */
    public static function faqToText(array $faq): string
    {
        return implode("\n\n", array_map(static fn (array $item): string => $item['q']."\n".$item['a'], $faq));
    }
}
