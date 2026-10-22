<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Способи оплати v1 (ADR D9): накладений платіж і безготівковий; онлайн (WayForPay) — після запуску №1. */
enum PaymentMethod: string implements TranslatableInterface
{
    case CashOnDelivery = 'cod';
    case BankTransfer = 'bank_transfer';
    case ManagerContact = 'manager_contact';

    /** @return list<self> */
    public static function selectable(): array
    {
        return [self::CashOnDelivery, self::BankTransfer];
    }

    /** Ключ підпису в домені admin: enum.payment_method.<код>. */
    public function transKey(): string
    {
        return 'enum.payment_method.'.$this->value;
    }

    /** Підпис мовою адмінки: поля й фільтри EasyAdmin, {{ value|trans }} у Twig, листи адміністраторам. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->transKey(), [], 'admin', $locale);
    }
}
