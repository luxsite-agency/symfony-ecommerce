<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Ai;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Що саме просили у моделі — для журналу використання і промптів (PR-7). check — «Перевірити з'єднання». */
enum AiFeature: string implements TranslatableInterface
{
    case Check = 'check';
    case Describe = 'describe';
    case Translate = 'translate';
    case Seo = 'seo';

    /** Українською — рядкове подання AiUsage (__toString); в адмінці — trans(). */
    public function label(): string
    {
        return match ($this) {
            self::Check => 'перевірка ключа',
            self::Describe => 'опис',
            self::Translate => 'переклад',
            self::Seo => 'SEO-мета',
        };
    }

    /** Підпис у журналі AI мовою адмінки (enum.ai_feature). */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.ai_feature.'.$this->value, [], 'admin', $locale);
    }
}
