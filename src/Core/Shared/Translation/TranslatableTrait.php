<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Translation;

/**
 * Доступ до перекладів за локаллю. Клас, що використовує трейт, оголошує
 * `Collection<string, T> $translations` (OneToMany з indexBy: 'locale') і фабрику createTranslation().
 *
 * @template T of AbstractTranslation
 */
trait TranslatableTrait
{
    /**
     * Переклад указаною мовою; створює порожній, якщо його ще нема.
     *
     * @return T
     */
    public function translate(string $locale): AbstractTranslation
    {
        $locale = AbstractTranslation::normalizeLocale($locale);
        $translation = $this->translations->get($locale);
        if (null === $translation) {
            $translation = $this->createTranslation($locale);
            $this->translations->set($locale, $translation);
        }

        return $translation;
    }

    /** @return T|null */
    public function getTranslation(string $locale): ?AbstractTranslation
    {
        return $this->translations->get(AbstractTranslation::normalizeLocale($locale));
    }

    public function removeTranslation(string $locale): void
    {
        $this->translations->remove(AbstractTranslation::normalizeLocale($locale));
    }

    /** @return list<string> відсортовані: порядок завантаження з БД не визначений */
    public function getTranslatedLocales(): array
    {
        $locales = array_values(array_map(static fn (AbstractTranslation $t): string => $t->getLocale(), $this->translations->toArray()));
        sort($locales);

        return $locales;
    }

    /**
     * Текст для показу (__toString): переклад мовою DisplayLocale, далі мовою за замовчуванням, далі будь-який непорожній
     * (за кодом мови, щоб не залежати від порядку завантаження). null — непорожнього нема.
     *
     * @param callable(T): string $text
     */
    protected function displayText(callable $text): ?string
    {
        $byLocale = [];
        foreach ($this->translations as $translation) {
            $value = $text($translation);
            if ('' !== $value) {
                $byLocale[$translation->getLocale()] = $value;
            }
        }
        foreach (DisplayLocale::preferred() as $locale) {
            if (isset($byLocale[$locale])) {
                return $byLocale[$locale];
            }
        }
        ksort($byLocale);

        return [] === $byLocale ? null : reset($byLocale);
    }

    /** @return T */
    abstract protected function createTranslation(string $locale): AbstractTranslation;
}
