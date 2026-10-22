<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\MessageHandler;

use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Export\CustomerDataExport;
use App\Core\Customer\Export\ExportFiles;
use App\Core\Customer\Message\ExportCustomerData;
use App\Core\Customer\Message\SendCustomerAuthMail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class ExportCustomerDataHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CustomerDataExport $export,
        private readonly ExportFiles $files,
        private readonly MessageBusInterface $bus,
        private readonly AuthAudit $audit,
    ) {
    }

    public function __invoke(ExportCustomerData $message): void
    {
        $customer = $this->em->find(Customer::class, $message->customerId);
        if (null === $customer || !$customer->isActive() || $customer->isDeleted()) {
            return; // акаунт видалено, поки чекав у черзі — файл не створюється, лист не йде
        }
        $this->files->write($message->fileName, json_encode($this->export->build($customer), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT));
        $this->bus->dispatch(new SendCustomerAuthMail(SendCustomerAuthMail::EXPORT, $customer->getEmail(), $customer->getName(), $customer->getLocale(), $message->mailUrl, 24 * 60));
        $this->audit->log('export_ready', $customer->getEmail(), $customer->getId());
    }
}
