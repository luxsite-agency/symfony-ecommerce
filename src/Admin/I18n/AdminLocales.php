<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\I18n;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Мови інтерфейсу адмінки — окремо від мов контенту магазину (app.locales). Адмінка перекладена повністю лише цими мовами
 * (translations/admin+intl-icu.*, validators.*); типова — мова магазину за замовчуванням, якщо адмінка нею перекладена,
 * інакше англійська. Для en-pharmacy (app.default_locale: en) адмінка відкривається англійською без ?_locale.
 */
final class AdminLocales
{
    public const FALLBACK = 'en';

    /** @param list<string> $locales app.admin_locales */
    public function __construct(
        #[Autowire('%app.admin_locales%')] private readonly array $locales,
        #[Autowire('%app.default_locale%')] private readonly string $storeDefaultLocale,
    ) {
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->locales;
    }

    public function supports(mixed $locale): bool
    {
        return \is_string($locale) && \in_array($locale, $this->locales, true);
    }

    /** Підпис у перемикачі: код країни-мови без прапорів — uk → UA (не UK), en → EN. */
    public static function label(string $locale): string
    {
        return 'uk' === $locale ? 'UA' : strtoupper($locale);
    }

    /** Мова адмінки без вибору користувача: мова магазину за замовчуванням або англійська. Нею ж ідуть листи адміністраторам. */
    public function default(): string
    {
        if ($this->supports($this->storeDefaultLocale)) {
            return $this->storeDefaultLocale;
        }

        return $this->supports(self::FALLBACK) ? self::FALLBACK : ($this->locales[0] ?? self::FALLBACK);
    }
}
