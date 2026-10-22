<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Pricing\Promotion\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Тип знижки акції: відсоток від ціни або фіксована сума за одиницю. */
enum PromotionType: string implements TranslatableInterface
{
    case Percent = 'percent';
    case Amount = 'amount';

    /** Ключ підпису в домені admin: enum.promotion_type.<код>. */
    public function transKey(): string
    {
        return 'enum.promotion_type.'.$this->value;
    }

    /** Підпис мовою адмінки: поля й фільтри EasyAdmin, {{ value|trans }} у Twig, листи адміністраторам. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->transKey(), [], 'admin', $locale);
    }
}
