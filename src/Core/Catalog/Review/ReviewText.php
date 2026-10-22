<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Review;

/**
 * Перевірка тексту відгуку (рішення власника 13.10.2026): лише звичайний текст, без HTML і посилань. Текст живої людини
 * не правимо (жодної типографіки, автокорекції чи вирізання) — HTML і посилання відхиляємо з поясненням, а зберігається
 * текст як є, обрізаний по краях. Вивід на сайті екранує Twig; nl2br — лише перенесення рядків.
 */
final class ReviewText
{
    /** HTML-тег або сутність: <b>, </p>, <a href…>, &lt; (хтось вставив екранований HTML); «x < y» — не тег, пропускається */
    private const string HTML = '/<\/?[a-zA-Z][^>]*>|&(?:[a-zA-Z]+|#\d+|#x[0-9a-fA-F]+);/u';
    /** Посилання: схема, www., домен із зоною і шляхом/слешем, mailto: */
    private const string LINK = '/(?:https?:\/\/|ftp:\/\/|mailto:|\bwww\.)|(?<![\w.])[\w-]+(?:\.[\w-]+)+\.(?:[a-z]{2,6})(?:\/\S*)?(?![\w])|(?<![\w.@])[\w-]+\.(?:com|net|org|ua|ru|info|biz|io|shop|store|site|online|top|xyz|club|pro|me|co|uk|de|pl|eu)(?:\/\S*|\b)/iu';

    /** @return 'html'|'link'|null код проблеми або null, якщо текст прийнятний */
    public static function problem(string $text): ?string
    {
        if (1 === preg_match(self::HTML, $text)) {
            return 'html';
        }
        if (1 === preg_match(self::LINK, $text)) {
            return 'link';
        }

        return null;
    }

    /** Нормалізація лише країв і переносів рядків (CRLF → LF): саме слово покупця лишається. */
    public static function normalize(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }
}
