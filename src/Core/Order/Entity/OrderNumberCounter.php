<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Лічильник номерів на період YYMM (ADR D6): рядок береться SELECT … FOR UPDATE у транзакції PlaceOrder. */
#[ORM\Entity]
#[ORM\Table(name: 'sales_order_number_counter')]
class OrderNumberCounter
{
    #[ORM\Id]
    #[ORM\Column(length: 4, options: ['fixed' => true])]
    private string $period;

    #[ORM\Column]
    private int $last = 0;

    public function __construct(string $period)
    {
        $this->period = $period;
    }

    public function getPeriod(): string
    {
        return $this->period;
    }

    public function getLast(): int
    {
        return $this->last;
    }
}
