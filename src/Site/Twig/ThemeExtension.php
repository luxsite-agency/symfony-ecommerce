<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Twig;

use App\Core\Cart\CartResolver;
use App\Site\Theme\SiteTheme;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Тема публічного сайту в шаблонах: site_theme('hero.image'), site_theme_text('support.title') мовою сторінки,
 * site_theme_style() — @font-face (swap, без preload) і CSS-змінні :root, site_asset() — URL файлу з public/ з
 * версією за вмістом (кеш браузера скидається при зміні), site_cart_count() — рядків у кошику для шапки.
 */
final class ThemeExtension extends AbstractExtension
{
    /** @var array<string, string> */
    private array $versions = [];

    public function __construct(
        private readonly SiteTheme $theme,
        private readonly Packages $packages,
        private readonly CartResolver $carts,
        private readonly RequestStack $requests,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicDir,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_theme', $this->theme->get(...)),
            new TwigFunction('site_theme_text', $this->text(...)),
            new TwigFunction('site_theme_style', $this->style(...), ['is_safe' => ['html']]),
            new TwigFunction('site_asset', $this->asset(...)),
            new TwigFunction('site_cart_count', $this->cartCount(...)),
        ];
    }

    public function text(string $path, ?string $locale = null): ?string
    {
        $locale ??= $this->requests->getCurrentRequest()?->getLocale() ?? $this->defaultLocale;

        return $this->theme->text($path, $locale, $this->defaultLocale);
    }

    /** URL файлу з public/ з ?v=<хеш вмісту>; порожній шлях — null, абсолютний URL — як є. */
    public function asset(?string $path): ?string
    {
        if (null === $path || '' === $path) {
            return null;
        }
        if (preg_match('~^(https?:)?//~', $path)) {
            return $path;
        }
        $path = ltrim($path, '/');
        if (!isset($this->versions[$path])) {
            $file = $this->publicDir.'/'.$path;
            $this->versions[$path] = is_file($file) ? substr((string) md5_file($file), 0, 10) : '';
        }
        $url = $this->packages->getUrl($path);

        return '' === $this->versions[$path] ? $url : $url.'?v='.$this->versions[$path];
    }

    public function style(): Markup
    {
        $css = '';
        $family = $this->theme->fontFamily();
        if (null !== $family) {
            foreach ($this->theme->fontFaces() as $weight => $path) {
                $css .= \sprintf('@font-face{font-family:"%s";font-style:normal;font-weight:%d;font-display:swap;src:url("%s") format("woff2")}', $family, $weight, $this->asset($path));
            }
        }
        $vars = '';
        foreach ($this->theme->cssVariables() as $name => $value) {
            $vars .= $name.':'.$value.';';
        }
        $placeholder = $this->theme->get('placeholder');
        if (\is_string($placeholder) && '' !== $placeholder && !preg_match('/["\'()\\\\;{}<>\s]/', $placeholder)) {
            $vars .= '--ph-image:url("'.$this->asset($placeholder).'");';
        }
        $css .= ':root{'.$vars.'color-scheme:'.('dark' === $this->theme->get('color_scheme') ? 'dark' : 'light').'}';

        return new Markup('<style>'.$css.'</style>', 'UTF-8');
    }

    /** Кількість рядків кошика (без створення кошика й cookie); 0 — кошика нема. */
    public function cartCount(): int
    {
        return $this->carts->current()?->getItems()->count() ?? 0;
    }
}
