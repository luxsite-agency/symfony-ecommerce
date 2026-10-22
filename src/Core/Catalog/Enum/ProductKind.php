<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Що продається (ADR Rev 5–7): товар, послуга без залишку, набір, товар-заявка без кошика. */
enum ProductKind: string implements TranslatableInterface
{
    case Goods = 'goods';
    case Service = 'service';
    case Bundle = 'bundle';
    case Quote = 'quote';

    /** Підпис мовою адмінки (домен admin, product.kind.<код>): ChoiceField і фільтри EasyAdmin, {{ value|trans }} у Twig. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('product.kind.'.$this->value, [], 'admin', $locale);
    }
}
