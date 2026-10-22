<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Способи доставки v1 (ADR D9); manager_contact — снепшот швидкого замовлення: спосіб узгоджує менеджер. */
enum ShippingMethod: string implements TranslatableInterface
{
    case Pickup = 'pickup';
    case Courier = 'courier';
    case NovaPoshta = 'nova_poshta';
    case ManagerContact = 'manager_contact';

    /** @return list<self> те, що обирає покупець у checkout */
    public static function selectable(): array
    {
        return [self::Pickup, self::Courier, self::NovaPoshta];
    }

    /** Ключ підпису в домені admin: enum.shipping_method.<код>. */
    public function transKey(): string
    {
        return 'enum.shipping_method.'.$this->value;
    }

    /** Підпис мовою адмінки: поля й фільтри EasyAdmin, {{ value|trans }} у Twig, листи адміністраторам. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->transKey(), [], 'admin', $locale);
    }
}
