<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Redirect;

use App\Core\Catalog\Url\Event\CategoryPathsChanged;
use App\Core\Seo\RedirectManager;
use App\Core\Seo\RedirectPath;
use App\Core\Seo\SlugChange;
use App\Core\Seo\SlugChangeRecorder;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Авто-301 (епік E3, рішення власника 2026-10-07): зміна адреси сутності → 301 зі старої адреси на нову, по кожній мові.
 * Адреси будуються тими самими маршрутами, що й посилання сайту (site_product, site_category, site_page, site_post), —
 * з мовним префіксом не-дефолтних мов. Мова поза app.locales (маршруту нема) — пропуск. Нова адреса (нова сутність
 * чи переклад) знімає правило з такої ж адреси: сторінка знову жива.
 */
final class AutoRedirects implements SlugChangeRecorder
{
    private const array ROUTES = [
        SlugChange::PRODUCT => ['site_product', 'slug'],
        SlugChange::CATEGORY => ['site_category', 'path'],
        SlugChange::PAGE => ['site_page', 'slug'],
        SlugChange::POST => ['site_post', 'slug'],
    ];

    public function __construct(private readonly RedirectManager $redirects, private readonly UrlGeneratorInterface $urls)
    {
    }

    public function record(iterable $changes): int
    {
        $moves = [];
        $appeared = [];
        foreach ($changes as $change) {
            $new = $this->path($change->type, $change->locale, $change->new);
            $old = null === $change->old ? null : $this->path($change->type, $change->locale, $change->old);
            if (null === $new) {
                continue;
            }
            if (null === $change->old) {
                $appeared[] = RedirectPath::normalize($new);
            } elseif (null !== $old) {
                $moves[] = [$old, $new];
            }
        }
        $appeared = array_values(array_filter($appeared, static fn (?string $p): bool => null !== $p));
        if ([] !== $appeared) {
            $this->redirects->release($appeared);
        }

        return [] === $moves ? 0 : $this->redirects->recordMoves($moves);
    }

    #[AsEventListener]
    public function onCategoryPathsChanged(CategoryPathsChanged $event): void
    {
        $this->record(array_map(static fn (array $c): SlugChange => new SlugChange(SlugChange::CATEGORY, $c['locale'], $c['old'], $c['new']), $event->changes));
    }

    private function path(string $type, string $locale, string $value): ?string
    {
        [$route, $param] = self::ROUTES[$type];
        try {
            $url = $this->urls->generate($route, [$param => $value, '_locale' => $locale]);
        } catch (RoutingException) {
            return null;
        }
        $base = $this->urls->getContext()->getBaseUrl();

        return '' !== $base && str_starts_with($url, $base) ? substr($url, \strlen($base)) : $url;
    }
}
