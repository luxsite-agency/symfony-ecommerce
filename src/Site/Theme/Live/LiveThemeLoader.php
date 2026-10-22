<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Theme\Live;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Лоадер живих шаблонів (var/share/theme/templates/site/**): у ланцюжку twig.loader стоїть ПЕРЕД FilesystemLoader рушія
 * (пріоритет тегу), тож site/home.html.twig звідси перекриває і пак (templates/store/), і рушій; @engine/… лишається базою
 * для {% extends %}. Відмінність від service-engine (там — ще один шлях FilesystemLoader і ручне скидання компільованого
 * шаблону): ключ кешу тут — хеш вмісту файлу, тому кожна версія компілюється в НОВИЙ клас, і ні компільований кеш Twig, ні
 * opcache, ні вже завантажений у процесі клас не віддадуть стару версію після put/revert/delete — скидати нічого не треба.
 * Теки може не бути (стенд без тома, свіжий образ) — лоадер просто нічого не знаходить, на відміну від addPath/prependPath,
 * які кидають LoaderError.
 */
#[AutoconfigureTag('twig.loader', ['priority' => 10])]
final class LiveThemeLoader implements LoaderInterface
{
    public function __construct(private readonly LiveTheme $theme)
    {
    }

    public function getSourceContext(string $name): Source
    {
        $file = $this->file($name) ?? throw new LoaderError(\sprintf('Живого шаблону «%s» нема.', $name));

        return new Source((string) file_get_contents($file), $name, $file);
    }

    public function getCacheKey(string $name): string
    {
        $file = $this->file($name) ?? throw new LoaderError(\sprintf('Живого шаблону «%s» нема.', $name));

        return 'live:'.$name.':'.md5_file($file);
    }

    public function isFresh(string $name, int $time): bool
    {
        $file = $this->file($name);

        return null !== $file && filemtime($file) <= $time;
    }

    public function exists(string $name): bool
    {
        return null !== $this->file($name);
    }

    private function file(string $name): ?string
    {
        if (!LiveTheme::isTemplateName($name)) {
            return null;
        }
        $file = $this->theme->templatesDir().'/'.$name;

        return is_file($file) && !LiveTheme::hasSymlink($this->theme->templatesDir(), $name) ? $file : null;
    }
}
