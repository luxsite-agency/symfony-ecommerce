<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Demo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Мови демо-даних — усі з app.locales (без хардкоду набору). Для мови, якої нема в DemoTexts, береться текст мови
 * за замовчуванням (або першої наявної) без жодних позначок: вітрина не показує технічних міток. Про брак перекладу
 * знає лише лог сідера (takeMissing(): мова → кількість текстів без перекладу).
 */
final class DemoLocales
{
    /** @var array<string, int> мова → скільки текстів узято з мови за замовчуванням */
    private array $missing = [];

    /** @param list<string> $locales */
    public function __construct(
        private readonly SluggerInterface $slugger,
        #[Autowire('%app.locales%')] private readonly array $locales,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
    ) {
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->locales;
    }

    public function default(): string
    {
        return $this->defaultLocale;
    }

    /** @param array<string, string> $texts мова → текст */
    public function text(array $texts, string $locale): string
    {
        if (isset($texts[$locale])) {
            return $texts[$locale];
        }
        $this->missing[$locale] = ($this->missing[$locale] ?? 0) + 1;

        return $this->fallback($texts);
    }

    /**
     * Те саме для HTML.
     *
     * @param array<string, string> $texts
     */
    public function html(array $texts, string $locale): string
    {
        return $this->text($texts, $locale);
    }

    /**
     * Список рядків (переваги варіанта рішення тощо): мовою або мовою за замовчуванням.
     *
     * @param array<string, list<string>> $lists
     *
     * @return list<string>
     */
    public function lines(array $lists, string $locale): array
    {
        if (isset($lists[$locale])) {
            return $lists[$locale];
        }
        $this->missing[$locale] = ($this->missing[$locale] ?? 0) + 1;

        return $lists[$this->defaultLocale] ?? ($lists[array_key_first($lists) ?? ''] ?? []);
    }

    /**
     * Чи переписати наявний переклад демо-запису при повторному сіді: його нема, він порожній, має стару позначку
     * « [xx]» (до 20.10.2026) або це копія тексту мови за замовчуванням, а тепер для мови є власний текст. Текст,
     * який змінили в адмінці, сюди не потрапляє й лишається як є.
     *
     * @param array<string, string> $texts
     */
    public function isStale(?string $current, array $texts, string $locale): bool
    {
        if (null === $current || '' === trim($current) || str_ends_with($current, ' ['.$locale.']')) {
            return true;
        }

        return isset($texts[$locale]) && $current !== $texts[$locale] && $current === $this->fallback($texts);
    }

    /**
     * Скільки текстів з моменту останнього виклику взято з мови за замовчуванням (мова → кількість); лічильник обнуляється.
     *
     * @return array<string, int>
     */
    public function takeMissing(): array
    {
        $missing = $this->missing;
        $this->missing = [];

        return $missing;
    }

    /**
     * Звичайний текст у абзаці (описи товарів і категорій шаблон виводить як HTML).
     *
     * @param array<string, string> $texts
     */
    public function paragraph(array $texts, string $locale): string
    {
        return '<p>'.htmlspecialchars($this->text($texts, $locale), \ENT_QUOTES).'</p>';
    }

    /**
     * Слаг з тексту цієї мови (транслітерація за правилами мови) або, без перекладу, — з тексту мови за замовчуванням:
     * слаги унікальні в межах мови, тож однаковий слаг у різних мовах допустимий.
     *
     * @param array<string, string> $texts
     */
    public function slug(array $texts, string $locale, string $prefix = ''): string
    {
        [$source, $sourceLocale] = isset($texts[$locale]) ? [$texts[$locale], $locale] : [$this->fallback($texts), $this->fallbackLocale($texts)];
        // м'який знак транслітерація дає апострофом → «sklads-kyy»; у слагах його просто прибираємо
        $slug = $this->slugger->slug(str_replace(['×', '’', "'", 'ь', 'Ь', 'ъ', 'Ъ'], ['x', '', '', '', '', '', ''], $source), '-', $sourceLocale)->lower()->toString();

        return mb_substr($prefix.trim($slug, '-'), 0, 180);
    }

    /** @param array<string, string> $texts */
    private function fallback(array $texts): string
    {
        return $texts[$this->fallbackLocale($texts)] ?? '';
    }

    /** @param array<string, string> $texts */
    private function fallbackLocale(array $texts): string
    {
        if (isset($texts[$this->defaultLocale])) {
            return $this->defaultLocale;
        }

        return (string) array_key_first($texts);
    }
}
