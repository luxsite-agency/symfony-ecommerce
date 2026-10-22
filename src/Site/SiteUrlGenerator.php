<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\CategoryTranslation;
use App\Core\Catalog\Entity\Product;
use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\Post;
use App\Core\Content\Entity\PostTranslation;
use App\Core\Content\Entity\Solution;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Публічні абсолютні URL сутностей для адмінки («Переглянути на сайті»), sitemap і hreflang. Маршрути site_* без host,
 * тому абсолютна адреса будується з параметра site_host (канонічний домен магазину), а не з хоста поточного запиту —
 * з адмінки виходить адреса магазину. null — сутність не має публічної сторінки цією мовою (нема перекладу/слага або
 * не опублікована).
 */
final class SiteUrlGenerator
{
    /** @param list<string> $locales */
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%app.locales%')] private readonly array $locales,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
        #[Autowire('%site_host%')] private readonly string $siteHost,
    ) {
    }

    /**
     * Абсолютний URL на канонічному домені магазину (site_host), незалежно від хоста поточного запиту.
     *
     * @param array<string, mixed> $params
     */
    public function absolute(string $route, array $params = []): string
    {
        return 'https://'.$this->siteHost.$this->urls->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    /** @return list<string> */
    public function locales(): array
    {
        return $this->locales;
    }

    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function page(Page $page, ?string $locale = null, bool $onlyPublished = true): ?string
    {
        $locale ??= $this->defaultLocale;
        $slug = $page->getTranslation($locale)?->getSlug();
        if (null === $slug || ($onlyPublished && !$page->isPublished())) {
            return null;
        }

        return $this->absolute('site_page', ['slug' => $slug, '_locale' => $locale]);
    }

    public function post(Post $post, ?string $locale = null, bool $onlyPublished = true): ?string
    {
        $locale ??= $this->defaultLocale;
        // URL мовою є лише для повного перекладу (заголовок + текст): інакше /en/blog/<slug> — 404 і не в hreflang/sitemap
        $translation = $post->getTranslation($locale);
        $slug = $translation instanceof PostTranslation && $translation->isComplete() ? $translation->getSlug() : null;
        if (null === $slug || ($onlyPublished && !$post->isPublished())) {
            return null;
        }

        return $this->absolute('site_post', ['slug' => $slug, '_locale' => $locale]);
    }

    /** Рішення мовою $locale: лише опубліковане й з перекладом (назва і слаг) цією мовою — інакше null (404, без hreflang/sitemap). */
    public function solution(Solution $solution, ?string $locale = null, bool $onlyPublished = true): ?string
    {
        $locale ??= $this->defaultLocale;
        $translation = $solution->getTranslation($locale);
        $slug = null !== $translation && '' !== $translation->getTitle() ? $translation->getSlug() : null;
        if (null === $slug || ($onlyPublished && !$solution->isPublished())) {
            return null;
        }

        return $this->absolute('site_solution', ['slug' => $slug, '_locale' => $locale]);
    }

    public function product(Product $product, ?string $locale = null, bool $onlyPublished = true): ?string
    {
        $locale ??= $this->defaultLocale;
        $slug = $product->getTranslation($locale)?->getSlug();
        if (null === $slug || ($onlyPublished && !$product->isActive())) {
            return null;
        }

        return $this->absolute('site_product', ['slug' => $slug, '_locale' => $locale]);
    }

    public function category(Category $category, ?string $locale = null, bool $onlyPublished = true): ?string
    {
        $locale ??= $this->defaultLocale;
        // адреса категорії — повний шлях за деревом (/odyah/futbolky/), не слаг
        $translation = $category->getTranslation($locale);
        $path = $translation instanceof CategoryTranslation ? $translation->getPath() : null;
        if (null === $path || ($onlyPublished && !$category->isActive())) {
            return null;
        }

        return $this->absolute('site_category', ['path' => $path, '_locale' => $locale]);
    }

    public function blog(string $locale): string
    {
        return $this->absolute('site_blog', ['_locale' => $locale]);
    }

    public function home(string $locale): string
    {
        return $this->absolute('site_home', ['_locale' => $locale]);
    }
}
