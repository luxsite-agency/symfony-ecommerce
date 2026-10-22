<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Seo;

use App\Core\Shared\Site\SiteName;
use App\Site\Theme\SiteTheme;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Title і description головної (SEO п. 9): окремі тексти теми seo.home_title / seo.home_description по мовах;
 * без них — як було: «brand.name | brand.tagline» (або назва магазину) і текст hero, а без hero — вступ головної
 * з перекладів (site.home.intro, той самий, що в банері).
 */
final class HomeSeo
{
    public function __construct(
        private readonly SiteTheme $theme,
        private readonly SiteName $siteName,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function title(string $locale, string $defaultLocale): string
    {
        $custom = $this->exact('seo.home_title', $locale);
        if (null !== $custom) {
            return $custom;
        }
        $name = $this->theme->text('brand.name', $locale, $defaultLocale) ?? $this->siteName->forLocale($locale);
        $tagline = $this->theme->text('brand.tagline', $locale, $defaultLocale);

        return null === $tagline ? $name : $name.' | '.$tagline;
    }

    public function description(string $locale, string $defaultLocale): string
    {
        return $this->exact('seo.home_description', $locale)
            ?? $this->theme->text('hero.text', $locale, $defaultLocale)
            ?? $this->translator->trans('site.home.intro', [], null, $locale);
    }

    /** SEO-текст саме цією мовою, без фолбеку на іншу: uk-title на en-сторінці гірший за автоматичний. */
    private function exact(string $path, string $locale): ?string
    {
        $value = $this->theme->get($path);
        if (\is_array($value)) {
            $value = $value[$locale] ?? null;
        }
        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
            return null;
        }
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
