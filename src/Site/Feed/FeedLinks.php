<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Feed;

use App\Site\SiteUrlGenerator;
use DateTimeImmutable;

/** Адреси фідів для підключення в Merchant Center і Meta Commerce Manager та час останньої генерації — для адмінки. */
final readonly class FeedLinks
{
    public function __construct(private MerchantFeedPaths $paths, private SiteUrlGenerator $urls)
    {
    }

    /** @return list<array{channel: string, label: string, locale: string, url: string, generatedAt: ?DateTimeImmutable}> */
    public function all(): array
    {
        $links = [];
        foreach (FeedChannel::cases() as $channel) {
            foreach ($this->paths->locales() as $locale) {
                $links[] = [
                    'channel' => $channel->value,
                    'label' => $channel->label(),
                    'locale' => $locale,
                    'url' => $this->urls->absolute($channel->route(), ['locale' => $locale]),
                    'generatedAt' => $this->paths->generatedAt($locale, $channel),
                ];
            }
        }

        return $links;
    }
}
