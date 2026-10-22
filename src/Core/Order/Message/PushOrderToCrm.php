<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Message;

/** Замовлення → CRM (ADR D7): диспатчиться в транзакції PlaceOrder у doctrine-транспорт (outbox). */
final readonly class PushOrderToCrm
{
    public function __construct(public int $orderId)
    {
    }
}
