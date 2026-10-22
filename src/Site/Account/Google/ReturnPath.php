<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Google;

/**
 * Куди повернути покупця після входу через Google: лише відносний шлях цього сайту (`/…`), без схеми, хоста, `//`, `\`
 * і керівних символів (open redirect). Шляхи самого потоку Google і виходу — не ціль. Решта → null (кабінет).
 */
final class ReturnPath
{
    public const int MAX = 2000;

    public static function sanitize(?string $path): ?string
    {
        if (null === $path || '' === $path || \strlen($path) > self::MAX || '/' !== $path[0]) {
            return null;
        }
        if (isset($path[1]) && ('/' === $path[1] || '\\' === $path[1])) {
            return null;
        }
        if (1 === preg_match('#[\x00-\x20\x7f\\\\]|^/(?:[a-z]{2,3}(?:_[A-Z]{2})?/)?account/(?:google/|logout)#i', $path)) {
            return null;
        }

        return $path;
    }
}
