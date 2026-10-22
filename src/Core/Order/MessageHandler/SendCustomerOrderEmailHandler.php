<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\MessageHandler;

use App\Core\Order\Entity\Order;
use App\Core\Order\Mail\OrderMailer;
use App\Core\Order\Message\SendCustomerOrderEmail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendCustomerOrderEmailHandler
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly OrderMailer $mailer)
    {
    }

    public function __invoke(SendCustomerOrderEmail $message): void
    {
        $order = $this->em->find(Order::class, $message->orderId);
        if (null !== $order) {
            $this->mailer->sendCustomerEmail($order);
        }
    }
}
