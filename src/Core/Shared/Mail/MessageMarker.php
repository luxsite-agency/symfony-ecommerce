<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Mail;

use App\Core\Shared\Runtime\BuildInfo;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Message;

/** Заголовок X-Mailer з назвою рушія в кожному листі (замовлення, кабінет, опт, зміни) — підпис рушія, як ResponseMarker. */
#[AsEventListener(event: MessageEvent::class)]
final class MessageMarker
{
    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if ($message instanceof Message && !$message->getHeaders()->has('X-Mailer')) {
            $message->getHeaders()->addTextHeader('X-Mailer', BuildInfo::NAME);
        }
    }
}
