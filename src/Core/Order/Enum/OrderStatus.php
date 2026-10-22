<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Стани замовлення (ADR D6): переходи — лише через Workflow «order» і OrderTransitionService. */
enum OrderStatus: string implements TranslatableInterface
{
    case New = 'new';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** Ключ підпису в домені admin: enum.order_status.<код>. */
    public function transKey(): string
    {
        return 'enum.order_status.'.$this->value;
    }

    /** Підпис мовою адмінки: поля й фільтри EasyAdmin, {{ value|trans }} у Twig, листи адміністраторам. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->transKey(), [], 'admin', $locale);
    }
}
