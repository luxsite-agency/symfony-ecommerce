<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Mcp;

/**
 * Тексти каталогу приходять з фідів постачальників і форм — для моделі це дані, не інструкції (consilium critic.md §2, п. 3).
 * Інструменти читання віддають довгі поля як plain text (HTML → текст), обрізаними й загорнутими в маркер
 * <untrusted_content source="product:7:uk:description">…</untrusted_content>. Це пом'якшення prompt-injection; захист —
 * у тому, що запис іде лише пакетом змін, який застосовує людина в адмінці.
 */
final class UntrustedText
{
    public const int LIMIT = 4000;

    public static function wrap(string $source, string $text): string
    {
        return \sprintf('<untrusted_content source="%s">%s</untrusted_content>', $source, str_replace('</untrusted_content', "</untrusted_content\u{200B}", $text));
    }

    /** HTML → текст без тегів і зайвих пробілів; довше за LIMIT — обрізано з позначкою. */
    public static function plain(string $html, int $limit = self::LIMIT): string
    {
        $text = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = trim(preg_replace("/\s*\n\s*/u", "\n", $text) ?? $text);
        $length = mb_strlen($text);
        if ($length <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit)).\sprintf(' …[обрізано: %d із %d символів; повний текст — параметр fields]', $limit, $length);
    }
}
