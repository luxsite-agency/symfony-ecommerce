<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Відповідь на адресу з менеджера редиректів: 301/302 — переадресація на target, 404/410 — сторінка помилки сайту. */
enum RedirectCode: int implements TranslatableInterface
{
    case Permanent = 301;
    case Temporary = 302;
    case NotFound = 404;
    case Gone = 410;

    public function isRedirect(): bool
    {
        return self::Permanent === $this || self::Temporary === $this;
    }

    /** Підпис в адмінці (поле й фільтр «Код»): мовою адмінки, translations/admin+intl-icu (enum.redirect_code). */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.redirect_code.'.$this->value, [], 'admin', $locale);
    }
}
