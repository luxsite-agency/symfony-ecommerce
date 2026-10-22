<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Feed;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Де лежать файли фідів: app.merchant_feed_dir (public/feeds)/<префікс каналу><мова>.xml — google-merchant-uk.xml,
 * meta-uk.xml. Генератор пише, маршрут і nginx віддають. У тестах — окрема тека, щоб не перезаписати dev-фід.
 */
final readonly class MerchantFeedPaths
{
    /** @param list<string> $locales */
    public function __construct(
        #[Autowire('%app.merchant_feed_dir%')] private string $dir,
        #[Autowire('%app.locales%')] private array $locales,
    ) {
    }

    public function file(string $locale, FeedChannel $channel = FeedChannel::Google): string
    {
        if (!\in_array($locale, $this->locales, true)) {
            throw new InvalidArgumentException(\sprintf('Мови "%s" нема в app.locales', $locale));
        }

        return rtrim($this->dir, '/').'/'.$channel->fileName($locale);
    }

    public static function name(string $locale, FeedChannel $channel = FeedChannel::Google): string
    {
        return $channel->fileName($locale);
    }

    /** Час останньої успішної генерації (файл з'являється лише атомарним rename), null — ще не генерувався. */
    public function generatedAt(string $locale, FeedChannel $channel): ?DateTimeImmutable
    {
        $file = $this->file($locale, $channel);
        clearstatcache(true, $file);
        $mtime = is_file($file) ? filemtime($file) : false;

        return false === $mtime ? null : (new DateTimeImmutable('@'.$mtime))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    /**
     * Тимчасові файли генератора (<фід>.<hex>.tmp) усіх каналів, що лишилися після перерваного запису.
     *
     * @return list<string>
     */
    public function orphanTemps(): array
    {
        $orphans = [];
        foreach (FeedChannel::cases() as $channel) {
            array_push($orphans, ...(glob(rtrim($this->dir, '/').'/'.$channel->prefix().'*.xml.*.tmp') ?: []));
        }

        return $orphans;
    }

    /** @return list<string> */
    public function locales(): array
    {
        return $this->locales;
    }
}
