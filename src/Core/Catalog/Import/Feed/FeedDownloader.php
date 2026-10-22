<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import\Feed;

use App\Core\Catalog\Import\Exception\UnsafeFeedException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Завантаження фіду за URL у тимчасовий файл (консиліум безпеки 10.10 п. 3): лише http(s); приватні, loopback і
 * link-local адреси заборонені, у тому числі після редиректів (NoPrivateNetworkHttpClient); не більше 3 редиректів;
 * загальний час запиту — app.feed_max_duration (timeout HttpClient — лише пауза між чанками); розмір відповіді обмежено app.feed_max_bytes — за Content-Length до читання і за фактичними байтами під час стрімінгу,
 * щоб нескінченний потік не переповнив /tmp. Тимчасовий файл видаляє викликач; при помилці — сам завантажувач.
 */
final class FeedDownloader
{
    public const int MAX_REDIRECTS = 3;

    private readonly HttpClientInterface $http;

    public function __construct(
        HttpClientInterface $http,
        #[Autowire('%app.feed_max_bytes%')] private readonly int $maxBytes = 512 * 1024 * 1024,
        /** idle між чанками, с (timeout HttpClient) */
        private readonly int $timeout = 60,
        /** увесь запит разом із редиректами, с — повільний «вічний» потік не тримає процес годинами */
        #[Autowire('%app.feed_max_duration%')] private readonly int $maxDuration = 1800,
    ) {
        $this->http = $http instanceof NoPrivateNetworkHttpClient ? $http : new NoPrivateNetworkHttpClient($http);
    }

    /** Адреса зі схемою (http://, ftp://, php://…) — на відміну від шляху до файлу; літера диска Windows (C:\) схемою не є. */
    public static function hasScheme(string $pathOrUrl): bool
    {
        return 1 === preg_match('#^[a-z][a-z0-9+.-]+:#i', $pathOrUrl);
    }

    /** @return string шлях до тимчасового файлу з тілом відповіді */
    public function download(string $url): string
    {
        if (1 !== preg_match('#^https?://#i', $url)) {
            throw new UnsafeFeedException(\sprintf('Фід %s: дозволено лише http(s) або шлях до локального файлу', $url));
        }
        $temp = tempnam(sys_get_temp_dir(), 'feed-');
        if (false === $temp) {
            throw new RuntimeException('Не вдалося створити тимчасовий файл');
        }
        $handle = fopen($temp, 'w');
        if (false === $handle) {
            @unlink($temp);
            throw new RuntimeException('Не вдалося відкрити тимчасовий файл');
        }
        try {
            $this->fetch($url, $handle);
        } catch (HttpException $e) {
            fclose($handle);
            @unlink($temp);
            // зокрема заборона приватної адреси (NoPrivateNetworkHttpClient), таймаут, обрив
            throw new RuntimeException(\sprintf('Фід %s не завантажено: %s', $url, $e->getMessage()), 0, $e);
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($temp);
            throw $e;
        }
        fclose($handle);

        return $temp;
    }

    /** @param resource $handle */
    private function fetch(string $url, $handle): void
    {
        $response = $this->http->request('GET', $url, ['timeout' => $this->timeout, 'max_duration' => $this->maxDuration, 'max_redirects' => self::MAX_REDIRECTS, 'buffer' => false]);
        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException(\sprintf('Фід %s недоступний: HTTP %d', $url, $response->getStatusCode()));
        }
        $declared = $response->getHeaders(false)['content-length'][0] ?? null;
        if (null !== $declared && (int) $declared > $this->maxBytes) {
            $response->cancel();
            throw new UnsafeFeedException(\sprintf('Фід %s: Content-Length %d байт понад ліміт %d (app.feed_max_bytes)', $url, (int) $declared, $this->maxBytes));
        }
        $written = 0;
        foreach ($this->http->stream($response) as $chunk) {
            $content = $chunk->getContent();
            $written += \strlen($content);
            if ($written > $this->maxBytes) {
                $response->cancel();
                throw new UnsafeFeedException(\sprintf('Фід %s: відповідь понад ліміт %d байт (app.feed_max_bytes), завантаження перервано', $url, $this->maxBytes));
            }
            if ('' !== $content && false === fwrite($handle, $content)) {
                throw new RuntimeException('Не вдалося записати тимчасовий файл');
            }
        }
    }
}
