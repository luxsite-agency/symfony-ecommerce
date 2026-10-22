<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\I18n;

use Symfony\Component\Intl\Languages;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Назва мови контенту мовою адмінки (вкладки перекладів: «Ukrainian», «Russian» для англійської адмінки) — з ICU, без ключів. */
final readonly class LanguageName implements TranslatableInterface
{
    public function __construct(private string $locale)
    {
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        $display = $locale ?? ($translator instanceof LocaleAwareInterface ? $translator->getLocale() : $this->locale);

        return Languages::exists($this->locale) ? mb_convert_case(Languages::getName($this->locale, $display), \MB_CASE_TITLE) : $this->locale;
    }
}
