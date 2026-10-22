<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Theme;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Тема публічного сайту з параметра розгортання app.theme (config/store/parameters.local.yaml): кольори, радіуси, шрифт,
 * логотип, назва/підзаголовок, банер головної, блок «куди йдуть кошти», футер. Параметр перекриває лише задані ключі,
 * решта — дефолти рушія (нейтральна світла тема, системний шрифт, без благодійного блоку).
 *
 * Логотип обов'язковий (правило власника 09.10.2026: у шапці кожного сайту — знак, не назва текстом): без logo.src тема
 * не збирається — ні в рушії (виняток при створенні сервісу), ні в паку (bin/store validate). Дефолт рушія для dev і
 * тестів — нейтральний знак assets/logo.svg у config/services.yaml, пак задає свій themes/<key>/….
 *
 * Тексти — рядок (для всіх мов) або мапа {locale: рядок}; шляхи до файлів — відносно public/ (asset()).
 * Кольори й розміри йдуть у CSS-змінні :root як є, тому значення з `;{}<>` відкидаються (не дати вийти з декларації).
 * Невідомий ключ теми — помилка (validate()), а не тиха втрата: перейменування ключа в новій версії рушія не має
 * непомітно зламати вигляд магазину. Схема ключів для пакетів магазинів — schema(), `bin/console store:validate`.
 */
final class SiteTheme
{
    /** @var array<string, mixed> */
    public const array DEFAULTS = [
        'key' => 'default',
        'color_scheme' => 'light',
        'colors' => [
            'bg' => '#ffffff',
            'surface' => '#f5f6f7',
            'surface_2' => '#eceef1',
            'ink' => '#1a1f24',
            'muted' => '#56616b',
            'line' => '#dde1e5',
            'accent' => '#0b4f9c',
            'accent_ink' => '#ffffff',
            'accent_bg' => '#0b4f9c',
            'accent_bg_hover' => '#093f7c',
            'link' => '#0b4f9c',
            'highlight' => '#0b4f9c',
            'ok' => '#1d7a3a',
            'warn' => '#8a5a00',
            'danger' => '#b42318',
            'header_bg' => '#ffffff',
            'footer_bg' => '#f5f6f7',
            'input_bg' => '#ffffff',
            'focus' => '#0b4f9c',
            'hero_bg' => '#f5f6f7',
            'support_bg' => '#eceef1',
            'support_ink' => '#1a1f24',
        ],
        'radius' => '8px',
        'radius_small' => '6px',
        // гумовий контейнер: до 1620 px — на всю ширину з відступами, далі по центру (рішення власника 15.10.2026).
        // Дефолт — фолбек у site.css (var(--container,1620px)), інлайн не виводиться: тоді своє --container перемагає
        // і з конфігу теми, і з CSS паку (його :root програв би інлайн-стилю site_theme_style()).
        'container' => null,
        'font' => [
            'family' => null,
            'fallback' => 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
            // вага => шлях до woff2 відносно public/; підключаються лише @font-face зі swap, БЕЗ preload (PSI: preload + @font-face = +2 с LCP)
            'faces' => [],
        ],
        'heading' => ['weight' => '700', 'letter_spacing' => 'normal', 'transform' => 'none'],
        'brand' => ['name' => null, 'tagline' => null],
        // SEO головної (п. 9): окремі title/description по мовах; порожньо — brand.name | brand.tagline і hero.text
        'seo' => ['home_title' => null, 'home_description' => null],
        // Реквізити продавця для schema.org OnlineStore на головній (п. 20); порожні поля не виводяться.
        // legal_name — юрназва (ФОП …), tax_id — ІПН/ЄДРПОУ, phone/email — фолбек на footer, address — структуровано
        'organization' => [
            'legal_name' => null,
            'tax_id' => null,
            'phone' => null,
            'email' => null,
            'address' => ['street' => null, 'city' => null, 'postal_code' => null, 'region' => null, 'country' => 'UA'],
            'same_as' => [],
        ],
        // src обов'язковий (validate()); дефолт для розгортання без паку — app.theme у config/services.yaml
        'logo' => ['src' => null, 'width' => 40, 'height' => 40],
        'favicon' => null,
        'apple_touch_icon' => null,
        // зображення-заглушка для карток без фото (у темі команди — емблема); null = нейтральна іконка з CSS
        'placeholder' => null,
        // додатковий CSS теми після assets/site.css
        'stylesheet' => null,
        'hero' => [
            'title' => null,
            'text' => null,
            'eyebrow' => null,
            'emblem' => null,
            'emblem_width' => null,
            'emblem_height' => null,
            'image' => null,
            'image_srcset' => null,
            'image_mobile' => null,
            'image_width' => null,
            'image_height' => null,
            'image_alt' => null,
            'cta_label' => null,
            'secondary_label' => null,
            'secondary_url' => null,
        ],
        // пункти під кнопкою «в кошик» на сторінці товару (доставка, оплата…), список текстів
        'product_perks' => [],
        'support' => [
            'enabled' => false,
            'title' => null,
            'text' => null,
            'points' => [],
            'button_label' => null,
            'button_url' => null,
            'note' => null,
        ],
        'footer' => [
            'about' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
            'hours' => null,
            // реквізити продавця (ФОП, ІПН, IBAN…) — з параметра розгортання, у футері й на оферті
            'legal' => null,
            // [{name: Instagram, url: https://…}]
            'socials' => [],
        ],
    ];

    /** @var array<string, mixed> */
    private readonly array $config;

    /**
     * @param array<string, mixed> $theme
     */
    public function __construct(#[Autowire('%app.theme%')] array $theme = [])
    {
        if ([] !== $errors = self::validate($theme)) {
            throw new InvalidArgumentException('app.theme: '.implode('; ', $errors));
        }
        $this->config = self::merge(self::DEFAULTS, $theme);
    }

    /**
     * Помилки теми: невідомий ключ у мапі з фіксованими ключами (colors, font, hero, footer…) або не-мапа на її місці;
     * порожній logo.src (лого в шапці обов'язкове). Вільні вузли не перевіряються — там, де в DEFAULTS скаляр, null чи
     * список: тексти {uk, en}, списки, font.faces, hero.image_srcset.
     *
     * @param array<mixed> $theme
     *
     * @return list<string>
     */
    public static function validate(array $theme): array
    {
        $errors = self::validateNode(self::DEFAULTS, $theme, '');
        if ([] === $errors && !self::hasLogo($theme)) {
            $errors[] = 'logo.src: обов’язковий — лого в шапці на кожному сайті (файл відносно public/, напр. themes/<key>/logo.svg)';
        }

        return $errors;
    }

    /** @param array<mixed> $theme */
    private static function hasLogo(array $theme): bool
    {
        $src = \is_array($theme['logo'] ?? null) ? ($theme['logo']['src'] ?? null) : null;

        return \is_string($src) && '' !== trim($src);
    }

    /**
     * Схема ключів теми: мапа з фіксованими ключами — масив ключ => підсхема, вільне значення — null.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $schema = [];
        foreach (self::DEFAULTS as $key => $default) {
            $schema[$key] = self::schemaNode($default);
        }

        return $schema;
    }

    private static function schemaNode(mixed $default): mixed
    {
        if (!self::isStrictMap($default)) {
            return null;
        }
        $node = [];
        foreach ($default as $key => $value) {
            $node[$key] = self::schemaNode($value);
        }

        return $node;
    }

    /**
     * @param array<mixed> $defaults
     *
     * @return list<string>
     */
    private static function validateNode(array $defaults, mixed $value, string $path): array
    {
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            return [('' === $path ? 'тема' : $path).': очікується мапа ключів'];
        }
        $errors = [];
        foreach ($value as $key => $item) {
            $keyPath = ('' === $path ? '' : $path.'.').$key;
            if (!\array_key_exists($key, $defaults)) {
                $errors[] = $keyPath.': невідомий ключ (дозволено: '.implode(', ', array_keys($defaults)).')';
            } elseif (self::isStrictMap($defaults[$key]) && null !== $item) {
                array_push($errors, ...self::validateNode($defaults[$key], $item, $keyPath));
            }
        }

        return $errors;
    }

    /** Мапа з фіксованими ключами: непорожній асоціативний масив у DEFAULTS. */
    private static function isStrictMap(mixed $default): bool
    {
        return \is_array($default) && [] !== $default && !array_is_list($default);
    }

    /** Значення за шляхом через крапку: get('colors.accent'), get('hero.image'). */
    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->config;
        foreach (explode('.', $path) as $key) {
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }

        return $node ?? $default;
    }

    /** Текст мовою сторінки: рядок як є; мапа — locale, потім мова за замовчуванням, потім перше непорожнє. */
    public function text(string $path, string $locale, string $defaultLocale): ?string
    {
        return self::localize($this->get($path), $locale, $defaultLocale);
    }

    public static function localize(mixed $value, string $locale, string $defaultLocale): ?string
    {
        if (\is_string($value) || \is_int($value) || \is_float($value)) {
            $value = trim((string) $value);

            return '' === $value ? null : $value;
        }
        if (!\is_array($value)) {
            return null;
        }
        foreach ([$locale, $defaultLocale] as $candidate) {
            if (isset($value[$candidate]) && \is_scalar($value[$candidate]) && '' !== trim((string) $value[$candidate])) {
                return trim((string) $value[$candidate]);
            }
        }
        foreach ($value as $item) {
            if (\is_scalar($item) && '' !== trim((string) $item)) {
                return trim((string) $item);
            }
        }

        return null;
    }

    /** @return array<string, string> ім'я CSS-змінної => значення (лише безпечні значення) */
    public function cssVariables(): array
    {
        $vars = [];
        foreach ((array) $this->get('colors', []) as $name => $value) {
            $vars['--c-'.str_replace('_', '-', (string) $name)] = $value;
        }
        $family = $this->fontFamily();
        $fallback = (string) $this->get('font.fallback', '');
        $vars['--font'] = null !== $family ? '"'.$family.'", '.$fallback : $fallback;
        $vars['--radius'] = $this->get('radius');
        $vars['--radius-sm'] = $this->get('radius_small');
        $vars['--container'] = $this->get('container');
        $vars['--h-weight'] = $this->get('heading.weight');
        $vars['--h-spacing'] = $this->get('heading.letter_spacing');
        $vars['--h-transform'] = $this->get('heading.transform');

        return array_filter(array_map(self::cssValue(...), $vars), static fn (?string $v): bool => null !== $v);
    }

    /**
     * @return array<int, string> вага => шлях до woff2
     */
    public function fontFaces(): array
    {
        $faces = [];
        foreach ((array) $this->get('font.faces', []) as $weight => $path) {
            if (is_numeric($weight) && \is_string($path) && '' !== $path && null !== self::cssValue($path)) {
                $faces[(int) $weight] = $path;
            }
        }
        ksort($faces);

        return $faces;
    }

    public function fontFamily(): ?string
    {
        $family = $this->get('font.family');

        return \is_string($family) && '' !== $family && null !== self::cssValue($family) && !str_contains($family, '"') ? $family : null;
    }

    private static function cssValue(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return '' === $value || preg_match('/[;{}<>\\\\]/', $value) ? null : $value;
    }

    /**
     * Рекурсивне злиття мап: списки (socials, points, srcset) і скаляри з параметра замінюють дефолт цілком.
     *
     * @param array<mixed> $defaults
     * @param array<mixed> $override
     *
     * @return array<mixed>
     */
    private static function merge(array $defaults, array $override): array
    {
        foreach ($override as $key => $value) {
            if (\is_array($value) && isset($defaults[$key]) && \is_array($defaults[$key]) && !array_is_list($defaults[$key]) && !array_is_list($value)) {
                $defaults[$key] = self::merge($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }
}
