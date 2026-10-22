<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Twig;

use App\Site\Seo\HomeSeo;
use App\Site\Seo\OrganizationSchema;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * SEO головної в шаблонах: site_home_seo() → {title, description}, site_organization_schema() → масив JSON-LD
 * OnlineStore (виводити через schema.ld()). Мова — сторінки, фолбек — за замовчуванням.
 */
final class SeoExtension extends AbstractExtension
{
    public function __construct(
        private readonly HomeSeo $homeSeo,
        private readonly OrganizationSchema $organization,
        private readonly RequestStack $requests,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_home_seo', $this->homeSeo(...)),
            new TwigFunction('site_organization_schema', $this->organizationSchema(...)),
        ];
    }

    /** @return array{title: string, description: string} */
    public function homeSeo(?string $locale = null): array
    {
        $locale ??= $this->locale();

        return ['title' => $this->homeSeo->title($locale, $this->defaultLocale), 'description' => $this->homeSeo->description($locale, $this->defaultLocale)];
    }

    /** @return array<string, mixed> */
    public function organizationSchema(?string $locale = null): array
    {
        return $this->organization->build($locale ?? $this->locale(), $this->defaultLocale);
    }

    private function locale(): string
    {
        return $this->requests->getCurrentRequest()?->getLocale() ?? $this->defaultLocale;
    }
}
