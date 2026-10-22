<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\MessageHandler;

use App\Core\Changeset\Entity\Changeset;
use App\Core\Changeset\Mail\ChangesetMailer;
use App\Core\Changeset\Message\SendChangesetAppliedMail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendChangesetAppliedMailHandler
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ChangesetMailer $mailer)
    {
    }

    public function __invoke(SendChangesetAppliedMail $message): void
    {
        $changeset = $this->em->find(Changeset::class, $message->changesetId);
        if (null !== $changeset) {
            $this->mailer->sendPriceApplied($changeset);
        }
    }
}
