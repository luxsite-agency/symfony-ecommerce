<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Тип характеристики: визначає, яка typed-колонка значення заповнена. */
enum AttributeType: string implements TranslatableInterface
{
    case Select = 'select';
    case MultiSelect = 'multiselect';
    case Number = 'number';
    case Boolean = 'boolean';

    /** Підпис мовою адмінки (домен admin, attribute.type.<код>): ChoiceField і фільтри EasyAdmin, {{ value|trans }} у Twig. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('attribute.type.'.$this->value, [], 'admin', $locale);
    }
}
