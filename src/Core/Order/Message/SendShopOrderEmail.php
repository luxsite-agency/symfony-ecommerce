<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Message;

/** Лист магазину (orders.notify_email) про нове замовлення — окреме повідомлення. */
final readonly class SendShopOrderEmail
{
    public function __construct(public int $orderId)
    {
    }
}
