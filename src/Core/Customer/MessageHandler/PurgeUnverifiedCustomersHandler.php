<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\MessageHandler;

use App\Core\Customer\Auth\AuthTokens;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Export\ExportFiles;
use App\Core\Customer\Message\PurgeUnverifiedCustomers;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Неперевірений покупець «займає» email лише app.customer_unverified_days (7): далі рядок видаляється (токени — каскадом),
 * і адресу може зареєструвати власник скриньки (консиліум 07.10, П5). Видалені акаунти (deleted_at) не чіпає: їх рядок і журнал
 * згод зберігаються постійно (рішення власника 11.10.2026; рев'ю #60, п. 1).
 */
#[AsMessageHandler]
final class PurgeUnverifiedCustomersHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthTokens $tokens,
        private readonly ExportFiles $exports,
        #[Autowire('%app.customer_unverified_days%')] private readonly int $days,
    ) {
    }

    public function __invoke(PurgeUnverifiedCustomers $message): int
    {
        $deleted = (int) $this->em->createQuery('DELETE FROM '.Customer::class.' c WHERE c.emailVerifiedAt IS NULL AND c.deletedAt IS NULL AND c.createdAt < :edge')
            ->setParameter('edge', new DateTimeImmutable('-'.$this->days.' days'))
            ->execute();
        $this->tokens->prune();
        $this->exports->prune();

        return $deleted;
    }
}
