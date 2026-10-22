<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo;

/**
 * Нормалізація адрес менеджера редиректів. Джерело (source) — шлях без домену, query і фрагмента, %-кодування розкрито,
 * нижній регістр, один провідний слеш, без подвійних; кінцевий слеш зберігається як є: у схемі URL він значущий
 * (/odyah/ — категорія, /odyah — товар). Мова — частина шляху (/ru/…), окремого поля нема.
 */
final class RedirectPath
{
    /** utf8mb4 × 768 = 3072 байти — межа ключа InnoDB (унікальний індекс на source). */
    public const int MAX_LENGTH = 768;
    public const int MAX_TARGET_LENGTH = 2048;

    /** null — не шлях (порожньо, керівні символи, задовге, невалідний UTF-8). Абсолютний URL → його шлях. */
    public static function normalize(string $value): ?string
    {
        return self::path($value, true, true);
    }

    /** Шлях запиту (Request::getPathInfo()) у формі джерела: «//a//b» — це шлях з подвійними слешами, не URL з хостом «a». */
    public static function fromRequest(string $pathInfo): ?string
    {
        return self::path($pathInfo, true, false);
    }

    private static function path(string $value, bool $lower, bool $url = false): ?string
    {
        $value = trim($value);
        if ($url && 1 === preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $value)) {
            $path = parse_url($value, \PHP_URL_PATH);
            if (false === parse_url($value)) {
                return null;
            }
            $value = \is_string($path) ? $path : '/';
        }
        $value = (string) preg_replace('/[?#].*$/s', '', $value);
        if ('' === $value) {
            return null;
        }
        $value = rawurldecode($value);
        if (!mb_check_encoding($value, 'UTF-8') || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return null;
        }
        $value = (string) preg_replace('#/{2,}#', '/', '/'.($lower ? mb_strtolower($value) : $value));

        return mb_strlen($value) > self::MAX_LENGTH ? null : $value;
    }

    public static function isAbsoluteUrl(string $value): bool
    {
        return 1 === preg_match('~^https?://[^/\s?#]+~i', $value);
    }

    /**
     * Ціль: абсолютний http(s) URL — як є (інший домен); шлях — без фрагмента, %-декодований, без подвійних слешів, але
     * в регістрі як є (статичні файли, сторонні шляхи), плюс query, якщо був (/search?q=x). Відносні адреси, «//host»,
     * пробіли й керівні символи (CR/LF у заголовку Location) — null.
     */
    public static function normalizeTarget(string $value): ?string
    {
        $value = trim($value);
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $value) || 1 === preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $value)) {
            return null;
        }
        if (self::isAbsoluteUrl($value)) {
            return \strlen($value) > self::MAX_TARGET_LENGTH || 1 === preg_match('/\s/', $value) ? null : $value;
        }
        if (!str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }
        $value = (string) preg_replace('/#.*$/s', '', $value);
        $query = '';
        if (false !== ($q = strpos($value, '?'))) {
            $query = 1 === \strlen(substr($value, $q)) ? '' : substr($value, $q);
            $value = substr($value, 0, $q);
        }
        $path = self::path($value, false);
        if (null === $path || \strlen($path.$query) > self::MAX_TARGET_LENGTH || 1 === preg_match('/\s/', $query)) {
            return null;
        }

        return $path.$query;
    }

    /**
     * Ціль, яка може бути чиїмось джерелом (шлях без query), у формі джерела (нижній регістр) — лише такі зливаються в
     * ланцюги; інакше null.
     */
    public static function targetPath(string $target): ?string
    {
        return self::isAbsoluteUrl($target) || str_contains($target, '?') ? null : self::normalize($target);
    }

    /** Абсолютна ціль на одному з $hosts (свій сайт) → шлях з query; інша ціль — як є (рев'ю #37, п. 3). */
    public static function localize(string $target, string ...$hosts): string
    {
        if (!self::isAbsoluteUrl($target)) {
            return $target;
        }
        $host = strtolower((string) parse_url($target, \PHP_URL_HOST));
        foreach ($hosts as $own) {
            $own = strtolower($own);
            if ('' !== $own && ($host === $own || $host === 'www.'.$own || 'www.'.$host === $own)) {
                $path = (string) (parse_url($target, \PHP_URL_PATH) ?: '/');
                $query = parse_url($target, \PHP_URL_QUERY);

                return self::normalizeTarget($path.(\is_string($query) && '' !== $query ? '?'.$query : '')) ?? $target;
            }
        }

        return $target;
    }

    /** Нормалізований шлях (може містити кирилицю, пробіли) → закодований для заголовка Location. */
    public static function encode(string $pathWithQuery): string
    {
        $query = '';
        if (false !== ($q = strpos($pathWithQuery, '?'))) {
            $query = substr($pathWithQuery, $q);
            $pathWithQuery = substr($pathWithQuery, 0, $q);
        }

        return implode('/', array_map(rawurlencode(...), explode('/', $pathWithQuery))).$query;
    }
}
