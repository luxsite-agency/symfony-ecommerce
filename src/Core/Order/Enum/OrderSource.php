<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Звідки прийшло замовлення (ADR D8): швидке в один клік або повний checkout; admin — створене менеджером;
 * price_request — запит ціни товару «ціна за запитом» (лід: ціна 0, без резерву, менеджер пропонує ціну);
 * lead — загальна заявка без товару з форми /request (контакти, компанія, коментар, вкладення; LeadRequestService).
 */
enum OrderSource: string implements TranslatableInterface
{
    case Quick = 'quick';
    case Checkout = 'checkout';
    case Admin = 'admin';
    case PriceRequest = 'price_request';
    case Lead = 'lead';

    /** Ключ підпису в домені admin: enum.order_source.<код>. */
    public function transKey(): string
    {
        return 'enum.order_source.'.$this->value;
    }

    /** Підпис мовою адмінки: поля й фільтри EasyAdmin, {{ value|trans }} у Twig, листи адміністраторам. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->transKey(), [], 'admin', $locale);
    }
}
