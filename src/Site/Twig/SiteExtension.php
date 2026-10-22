<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Twig;

use App\Core\Shared\Quantity\Quantity;
use App\Core\Shared\Translation\AbstractTranslation;
use App\Core\Shared\Translation\TranslatableInterface;
use App\Site\SiteNavigation;
use App\Site\SiteUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Функції публічних шаблонів: навігація, альтернативні URL для hreflang/перемикача мов, локалі.
 * site_alternates(route, params|null, entity|null): для сутності з перекладами — лише мови, де є слаг.
 */
final class SiteExtension extends AbstractExtension
{
    /** Мови, у яких типовий регіон для og:locale не збігається з кодом мови. */
    private const array OG_REGION = [
        'en' => 'en_US', 'uk' => 'uk_UA', 'cs' => 'cs_CZ', 'da' => 'da_DK', 'el' => 'el_GR', 'et' => 'et_EE', 'he' => 'he_IL',
        'ja' => 'ja_JP', 'ka' => 'ka_GE', 'kk' => 'kk_KZ', 'ko' => 'ko_KR', 'sl' => 'sl_SI', 'sv' => 'sv_SE', 'vi' => 'vi_VN',
        'zh' => 'zh_CN', 'be' => 'be_BY', 'hy' => 'hy_AM', 'nb' => 'nb_NO', 'sq' => 'sq_AL', 'sr' => 'sr_RS',
    ];

    /**
     * @param list<string>          $locales
     * @param array<string, string> $hreflang
     */
    public function __construct(
        private readonly SiteNavigation $navigation,
        private readonly SiteUrlGenerator $siteUrls,
        private readonly RequestStack $requests,
        #[Autowire('%app.locales%')] private readonly array $locales,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
        #[Autowire('%app.hreflang%')] private readonly array $hreflang,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_nav_pages', fn (string $place): array => $this->navigation->pages($place)),
            new TwigFunction('site_nav_categories', fn (): array => $this->navigation->rootCategories()),
            new TwigFunction('site_latest_posts', fn (int $limit = 6): array => $this->navigation->latestPosts($limit)),
            new TwigFunction('site_has_solutions', fn (): bool => $this->navigation->hasSolutions($this->locale())),
            new TwigFunction('site_alternates', $this->alternates(...)),
            new TwigFunction('site_locales', fn (): array => $this->locales),
            new TwigFunction('site_default_locale', fn (): string => $this->defaultLocale),
            new TwigFunction('site_hreflang', $this->hreflang(...)),
            new TwigFunction('site_og_locale', $this->ogLocale(...)),
            // кількість для показу без хвостових нулів: "5.000" → "5" (залишки, списки B2B)
            new TwigFunction('quantity_display', static fn (string $quantity): string => Quantity::display($quantity)),
            new TwigFunction('site_url', $this->entityUrl(...)),
            new TwigFunction('tr', $this->translation(...)),
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, string> локаль → абсолютний URL
     */
    public function alternates(string $route, array $params = [], ?TranslatableInterface $entity = null, string $slugParam = 'slug'): array
    {
        $result = [];
        foreach ($this->locales as $locale) {
            if (null !== $entity) {
                // ті самі правила, що в sitemap (SiteUrlGenerator): для запису — лише повний переклад, для категорії — шлях
                $url = $this->urlFor($entity, $locale);
                if (null === $url && !($entity instanceof \App\Core\Content\Entity\Page || $entity instanceof \App\Core\Content\Entity\Post || $entity instanceof \App\Core\Content\Entity\Solution || $entity instanceof \App\Core\Catalog\Entity\Product || $entity instanceof \App\Core\Catalog\Entity\Category)) {
                    $translation = $entity->getTranslation($locale);
                    $slug = null !== $translation && method_exists($translation, 'getSlug') ? $translation->getSlug() : null;
                    $url = null === $slug ? null : $this->siteUrls->absolute($route, $params + [$slugParam => $slug, '_locale' => $locale]);
                }
                if (null !== $url) {
                    $result[$locale] = $url;
                }
                continue;
            }
            $result[$locale] = $this->siteUrls->absolute($route, $params + ['_locale' => $locale]);
        }

        return $result;
    }

    /** URL сутності поточною мовою, інакше — мовою за замовчуванням (фолбек ADR Rev 8), інакше null. */
    public function entityUrl(object $entity, ?string $locale = null): ?string
    {
        $locale ??= $this->locale();
        $url = $this->urlFor($entity, $locale);

        return $url ?? ($locale !== $this->defaultLocale ? $this->urlFor($entity, $this->defaultLocale) : null);
    }

    /**
     * Переклад сутності поточною мовою або мовою за замовчуванням (фолбек ADR Rev 8). Неповний переклад (isComplete():
     * запис без заголовка чи тексту) теж відкочується на мову за замовчуванням — інакше картка порожня, а посилання
     * (site_url) веде на іншу мову.
     */
    public function translation(TranslatableInterface $entity, ?string $locale = null): ?AbstractTranslation
    {
        $locale ??= $this->locale();
        $translation = $entity->getTranslation($locale);
        if (null !== $translation && (!method_exists($translation, 'isComplete') || $translation->isComplete())) {
            return $translation;
        }

        return $entity->getTranslation($this->defaultLocale) ?? $translation;
    }

    /** Код hreflang з параметра app.hreflang (uk → uk-UA); відсутня мова — як є. */
    public function hreflang(string $locale): string
    {
        return $this->hreflang[$locale] ?? $locale;
    }

    /**
     * og:locale у форматі мова_РЕГІОН: з hreflang, якщо там є регіон (uk-UA → uk_UA), інакше типовий регіон мови
     * (en → en_US; Facebook не приймає голе «en»), для решти — код мови як регіон (pl → pl_PL).
     */
    public function ogLocale(string $locale): string
    {
        $code = str_replace('-', '_', $this->hreflang($locale));
        if (str_contains($code, '_')) {
            [$language, $region] = explode('_', $code, 2);

            return strtolower($language).'_'.strtoupper($region);
        }
        $code = strtolower($code);

        return self::OG_REGION[$code] ?? $code.'_'.strtoupper($code);
    }

    private function urlFor(object $entity, string $locale): ?string
    {
        return match (true) {
            $entity instanceof \App\Core\Content\Entity\Page => $this->siteUrls->page($entity, $locale),
            $entity instanceof \App\Core\Content\Entity\Post => $this->siteUrls->post($entity, $locale),
            $entity instanceof \App\Core\Content\Entity\Solution => $this->siteUrls->solution($entity, $locale),
            $entity instanceof \App\Core\Catalog\Entity\Product => $this->siteUrls->product($entity, $locale),
            $entity instanceof \App\Core\Catalog\Entity\Category => $this->siteUrls->category($entity, $locale),
            default => null,
        };
    }

    private function locale(): string
    {
        return $this->requests->getCurrentRequest()?->getLocale() ?? $this->defaultLocale;
    }
}
