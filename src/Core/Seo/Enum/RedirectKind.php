<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Seo\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Звідки редирект: заведений вручну (адмінка, імпорт CSV) чи записаний рушієм при зміні адреси (авто-301). */
enum RedirectKind: string implements TranslatableInterface
{
    case Manual = 'manual';
    case Auto = 'auto';

    /** Підпис в адмінці (поле й фільтр «Звідки»): мовою адмінки (enum.redirect_kind). */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.redirect_kind.'.$this->value, [], 'admin', $locale);
    }
}
