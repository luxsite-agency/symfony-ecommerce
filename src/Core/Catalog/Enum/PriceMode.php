<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Показ ціни (ADR Rev 7): фіксована, «від X», «за запитом». */
enum PriceMode: string implements TranslatableInterface
{
    case Fixed = 'fixed';
    case From = 'from';
    case OnRequest = 'on_request';

    /** Підпис мовою адмінки (домен admin, product.price_mode.<код>): ChoiceField і фільтри EasyAdmin, {{ value|trans }} у Twig. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('product.price_mode.'.$this->value, [], 'admin', $locale);
    }
}
