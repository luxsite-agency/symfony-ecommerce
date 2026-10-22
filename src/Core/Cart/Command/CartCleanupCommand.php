<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Cart\Command;

use App\Core\Cart\Entity\Cart;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Прострочені кошики (ADR D4: expiresAt + cleanup) — щоденно з cron. Рядки видаляє CASCADE. */
#[AsCommand(name: 'cart:cleanup', description: 'Видалити прострочені кошики')]
final class CartCleanupCommand
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $deleted = $this->em->createQuery('DELETE FROM '.Cart::class.' c WHERE c.expiresAt < :now')
            ->setParameter('now', new DateTimeImmutable())->execute();
        $io->success(\sprintf('Видалено прострочених кошиків: %d', (int) $deleted));

        return Command::SUCCESS;
    }
}
