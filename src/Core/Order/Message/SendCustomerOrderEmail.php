<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Message;

/** Лист покупцю про нове замовлення — окреме повідомлення (ретрай не дублює лист магазину і навпаки). */
final readonly class SendCustomerOrderEmail
{
    public function __construct(public int $orderId)
    {
    }
}
