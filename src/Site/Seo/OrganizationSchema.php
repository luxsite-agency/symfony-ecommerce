<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Seo;

use App\Core\Shared\Site\SiteName;
use App\Site\SiteUrlGenerator;
use App\Site\Theme\SiteTheme;
use Luxsite\GmbReviews\Client;
use Luxsite\GmbReviews\Renderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * JSON-LD OnlineStore для головної (SEO п. 20) з параметрів теми: name, url, logo, contactPoint, areaServed UA,
 * реквізити (legalName, taxID, address) — лише заповнені поля; parentOrganization не вказується (команда не продавець).
 * aggregateRating — загальна оцінка з віджета gbp-manager (app.gbp_reviews.token), ті самі числа, що в блоці відгуків
 * головної; єдиний aggregateRating магазину на сайті (власник 18.10.2026). Оцінку чи мікророзмітку вимкнено у віджеті — поля нема.
 */
final class OrganizationSchema
{
    public function __construct(
        private readonly SiteTheme $theme,
        private readonly SiteName $siteName,
        private readonly SiteUrlGenerator $urls,
        private readonly ?Client $gbp = null,
        #[Autowire('%app.gbp_reviews.token%')]
        private readonly string $gbpToken = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function build(string $locale, string $defaultLocale): array
    {
        $home = $this->urls->home($defaultLocale);
        $logo = $this->theme->get('logo.src');
        $phone = $this->theme->text('organization.phone', $locale, $defaultLocale) ?? $this->theme->text('footer.phone', $locale, $defaultLocale);
        $email = $this->theme->text('organization.email', $locale, $defaultLocale) ?? $this->theme->text('footer.email', $locale, $defaultLocale);
        $addressFields = self::compact([
            'streetAddress' => $this->theme->text('organization.address.street', $locale, $defaultLocale),
            'addressLocality' => $this->theme->text('organization.address.city', $locale, $defaultLocale),
            'addressRegion' => $this->theme->text('organization.address.region', $locale, $defaultLocale),
            'postalCode' => $this->theme->text('organization.address.postal_code', $locale, $defaultLocale),
        ]);
        // адреса — лише коли є щось крім країни; контакт — лише коли є телефон або пошта (не голі типи)
        $address = [] === $addressFields ? null : ['@type' => 'PostalAddress'] + $addressFields + self::compact(['addressCountry' => $this->theme->text('organization.address.country', $locale, $defaultLocale)]);
        $contact = null === $phone && null === $email ? null : self::compact(['@type' => 'ContactPoint', 'contactType' => 'customer service', 'telephone' => $phone, 'email' => $email, 'areaServed' => 'UA']);
        $sameAs = array_values(array_filter(array_map(static fn (mixed $u): string => \is_string($u) ? trim($u) : '', (array) $this->theme->get('organization.same_as', [])), static fn (string $u): bool => '' !== $u));

        return self::compact([
            '@context' => 'https://schema.org',
            '@type' => 'OnlineStore',
            '@id' => rtrim($home, '/').'/#organization',
            'name' => $this->theme->text('brand.name', $locale, $defaultLocale) ?? $this->siteName->forLocale($locale),
            'legalName' => $this->theme->text('organization.legal_name', $locale, $defaultLocale),
            'taxID' => $this->theme->text('organization.tax_id', $locale, $defaultLocale),
            'url' => $home,
            'logo' => self::absolute($home, $logo),
            'contactPoint' => $contact,
            'address' => $address,
            'areaServed' => 'UA',
            'sameAs' => [] === $sameAs ? null : $sameAs,
            'aggregateRating' => $this->aggregateRating(),
        ]);
    }

    /** @return array<string, string>|null */
    private function aggregateRating(): ?array
    {
        $data = null === $this->gbp || '' === $this->gbpToken ? null : $this->gbp->fetch($this->gbpToken);

        return null === $data ? null : Renderer::aggregateRating($data);
    }

    /** Логотип теми — абсолютною адресою: https://… і //cdn… як є, відносний шлях — від кореня сайту. */
    private static function absolute(string $home, mixed $src): ?string
    {
        if (!\is_string($src) || '' === ($src = trim($src))) {
            return null;
        }
        if (str_starts_with($src, '//')) {
            return 'https:'.$src;
        }
        if (1 === preg_match('#^https?://#i', $src)) {
            return $src;
        }

        return rtrim($home, '/').'/'.ltrim($src, '/');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function compact(array $data): array
    {
        return array_filter($data, static fn (mixed $v): bool => null !== $v && '' !== $v && [] !== $v);
    }
}
