<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\EventListener;

use App\Core\Changeset\Event\ChangesetApplied;
use App\Core\Changeset\Message\SendChangesetAppliedMail;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/** Ціновий пакет застосовано → лист відповідальним за ціни у чергу (рішення власника 11.10, п. 4: не адмінам). Контентний — без листа. */
#[AsEventListener]
final class ChangesetAppliedListener
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public function __invoke(ChangesetApplied $event): void
    {
        if ($event->hasPriceOperations) {
            $this->bus->dispatch(new SendChangesetAppliedMail($event->changesetId));
        }
    }
}
