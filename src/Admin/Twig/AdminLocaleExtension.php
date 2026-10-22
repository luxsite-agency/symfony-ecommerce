<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Twig;

use App\Admin\I18n\AdminLocales;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Перемикач мови адмінки «UA / EN» (admin/_locale_switcher.html.twig): посилання на ту саму сторінку з ?_locale=xx,
 * решта query зберігається. Мову застосовує й запамʼятовує AdminLocaleListener.
 */
final class AdminLocaleExtension extends AbstractExtension
{
    public function __construct(private readonly AdminLocales $locales, private readonly RequestStack $requests)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('admin_locale_links', $this->links(...))];
    }

    /** @return list<array{locale: string, label: string, url: string, current: bool}> */
    public function links(): array
    {
        $request = $this->requests->getMainRequest();
        if (null === $request) {
            return [];
        }
        $links = [];
        foreach ($this->locales->all() as $locale) {
            $query = http_build_query(['_locale' => $locale] + $request->query->all());
            $links[] = [
                'locale' => $locale,
                'label' => AdminLocales::label($locale),
                'url' => $request->getBaseUrl().$request->getPathInfo().'?'.$query,
                'current' => $locale === $request->getLocale(),
            ];
        }

        return $links;
    }
}
