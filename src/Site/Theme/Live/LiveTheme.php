<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Theme\Live;

use App\Site\Theme\Templates\StoreTemplates;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Живий дизайн під токеном (рішення власника 20.10.2026, модель service-engine «Дизайн під токеном»): файли в
 * %app.theme_live_dir% (var/share/theme — том share, переживає релізи; у service-engine — <root>/shared/theme):
 *   templates/site/**.twig  — Twig бере їх ПЕРШИМИ (LiveThemeLoader: live > пак templates/store > рушій templates),
 *   public/theme.css        — додатковий CSS після стилів рушія й паку (/theme-live/theme.css?v=…, LiveCssController),
 *   history/<шлях>/<версія> — попередні версії (ThemeService, до 20).
 * Тут — шляхи й правила імен, спільні для лоадера, контролера CSS і ThemeService. Дозволено лише те, що й темі паку
 * (StoreTemplates: site/**, без забороненої зони), плюс theme.css; шлях — нормалізований, без «..», символьних посилань і
 * службових файлів.
 */
final class LiveTheme
{
    public const string CSS = 'theme.css';
    public const string CSS_URL = '/theme-live/theme.css';
    /** Шаблон — лише site/**.twig з безпечних символів (той самий словник, що маршрут ThemeApiController). */
    public const string TEMPLATE_RE = '#^site/[A-Za-z0-9_][A-Za-z0-9_./-]*\.twig$#';

    public function __construct(#[Autowire('%app.theme_live_dir%')] private readonly string $root)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    public function templatesDir(): string
    {
        return $this->root.'/templates';
    }

    public function cssFile(): string
    {
        return $this->root.'/public/'.self::CSS;
    }

    /** Абсолютний шлях живого файлу за нормалізованим ім'ям (theme.css або site/….twig). */
    public function path(string $rel): string
    {
        return self::CSS === $rel ? $this->cssFile() : $this->templatesDir().'/'.$rel;
    }

    /** URL живого CSS з версією за вмістом; null — файлу нема (base.html.twig тоді нічого не підключає). */
    public function cssUrl(): ?string
    {
        $file = $this->cssFile();
        if (!is_file($file) || is_link($file)) {
            return null;
        }

        return self::CSS_URL.'?v='.substr((string) md5_file($file), 0, 10);
    }

    /**
     * Нормалізоване ім'я або причина відмови: theme.css чи site/**.twig без «..», «//», зайвих префіксів (templates/, /),
     * поза забороненою зоною паку (кабінет, _schema, sitemap, ліцензія). Повертає [ім'я, null] або [null, помилка].
     *
     * @return array{?string, ?string}
     */
    public static function normalize(string $path): array
    {
        $rel = ltrim(str_replace('\\', '/', trim($path)), '/');
        $rel = preg_replace('#^(templates/|public/)#', '', $rel) ?? $rel;
        if (self::CSS === $rel) {
            return [$rel, null];
        }
        if (str_contains($rel, '..') || str_contains($rel, '//') || str_contains($rel, '/./') || 1 !== preg_match(self::TEMPLATE_RE, $rel)) {
            return [null, 'Дозволені лише site/**.twig (латиниця, цифри, _ . - /) і theme.css'];
        }
        if (!StoreTemplates::isAllowed($rel) || StoreTemplates::isForbidden($rel)) {
            return [null, \sprintf('Заборонена зона (%s): кабінет, schema.org, sitemap і ліцензія лишаються рушію', implode(', ', StoreTemplates::FORBIDDEN))];
        }

        return [$rel, null];
    }

    /** Чи є ім'я живим шаблоном для лоадера: site/**.twig, не заборонена зона (файл у теці ще не означає, що його візьмуть). */
    public static function isTemplateName(string $name): bool
    {
        return 1 === preg_match(self::TEMPLATE_RE, $name) && !str_contains($name, '..') && !str_contains($name, '//') && !StoreTemplates::isForbidden($name);
    }

    /** Шлях «реальний»: жоден компонент від кореня до файлу не символьне посилання (обхід забороненої зони лінком, як у паку). */
    public static function hasSymlink(string $root, string $rel): bool
    {
        $current = $root;
        foreach (explode('/', $rel) as $segment) {
            $current .= '/'.$segment;
            if (is_link($current)) {
                return true;
            }
        }

        return false;
    }
}
