<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Theme\Templates;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Правила шаблонів паку магазину (епік E14, docs/themes.md). Пак може перекривати публічні шаблони рушія: файл
 * themes/<key>/templates/site/home.html.twig у паку стає site/home.html.twig у головному Twig-namespace (деплой кладе
 * теку в templates/store/, StoreTemplatesPass ставить її першою), а базовий шаблон рушія лишається доступним як
 * «@engine/site/home.html.twig» — звичайний шаблон паку робить {% extends '@engine/…' %} і перевизначає лише потрібні блоки.
 *
 * Дозволено лише site/** і лише *.twig. Заборонена зона — те, що тримає SEO, аналітику й безпеку і має отримувати
 * виправлення рушія без участі паку: кабінет (site/account/**), JSON-LD макроси (_schema*), sitemap, сторінка ліцензії платформи
 * (site/page/license). Та ж перевірка —
 * у bin/store.d/_validate.py (без PHP) до збірки; тут — запобіжник на компіляції контейнера (cache:warmup у Dockerfile).
 */
final class StoreTemplates
{
    /** Версія контракту шаблонів (блоки base, partial-и, змінні контролерів) — docs/themes.md, маніфест теми паку `contract`. */
    public const int CONTRACT = 1;

    /** Теки рушія (відносно templates/), які пак може перекривати. */
    public const array ALLOWED = ['site/'];

    /** Заборонена зона всередині дозволених тек: кабінет, schema.org-макроси, sitemap, сторінка ліцензії платформи. */
    public const array FORBIDDEN = ['site/account/', 'site/partials/_schema', 'site/sitemap', 'site/page/license'];

    /**
     * Порушення правил у теці шаблонів паку (templates/store/ образу або themes/<key>/templates/ паку): не-twig, шлях поза
     * site/**, заборонена зона. Порожній список — тека придатна для prependPath.
     *
     * @return list<string>
     */
    public static function violations(string $dir): array
    {
        $errors = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $rel = str_replace('\\', '/', substr($file->getPathname(), \strlen(rtrim($dir, '/\\')) + 1));
            // symlink (файл чи тека) — обхід забороненої зони: site/account -> ../_acc не бачать ні ітератор без
            // FOLLOW_SYMLINKS, ні os.walk валідатора, а cp -a/tar/COPY лінк зберігають і Twig його резолвить
            if ($file->isLink()) {
                $errors[] = $rel.': символьне посилання в шаблонах паку заборонене';
                continue;
            }
            if (!$file->isFile()) {
                continue;
            }
            if (\in_array($rel, ['README.md', '.gitkeep'], true)) {
                continue;
            }
            if (!str_ends_with($rel, '.twig')) {
                $errors[] = $rel.': у шаблонах паку лише *.twig (PHP, CSS і картинки — не тут)';
            } elseif (!self::isAllowed($rel)) {
                $errors[] = $rel.': перекривати можна лише '.implode(', ', array_map(static fn (string $p): string => $p.'**', self::ALLOWED));
            } elseif (self::isForbidden($rel)) {
                $errors[] = $rel.': заборонена зона ('.implode(', ', self::FORBIDDEN).') — кабінет, schema.org і sitemap лишаються рушію';
            }
        }
        sort($errors);

        return $errors;
    }

    public static function isAllowed(string $relativePath): bool
    {
        foreach (self::ALLOWED as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function isForbidden(string $relativePath): bool
    {
        foreach (self::FORBIDDEN as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
