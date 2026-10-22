<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\MessageHandler;

use App\Core\Integration\Crm\CrmConnectorRegistry;
use App\Core\Integration\Crm\Exception\CrmNotSupportedException;
use App\Core\Integration\Entity\ExternalRef;
use App\Core\Order\Entity\Order;
use App\Core\Order\Message\PushOrderToCrm;
use App\Core\Order\OrderExportFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Push замовлення в активну CRM (ADR D7): id замовлення в CRM — у ExternalRef(system, order). Конектор без pushOrder
 * (none, KeyCRM до Ф2-контракту) — лог і вихід; помилка API — виняток → retry Messenger → failed transport + алерт.
 */
#[AsMessageHandler]
final class PushOrderToCrmHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CrmConnectorRegistry $connectors,
        private readonly OrderExportFactory $exports,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PushOrderToCrm $message): void
    {
        $order = $this->em->find(Order::class, $message->orderId);
        if (null === $order) {
            $this->logger->warning('CRM push: замовлення не знайдено', ['id' => $message->orderId]);

            return;
        }
        $connector = $this->connectors->active();
        if (!$connector->capabilities()->pushOrder) {
            $this->logger->info('CRM push пропущено: конектор не приймає замовлення', ['system' => $connector->getSystem(), 'order' => $order->getNumber()]);

            return;
        }
        $refs = $this->em->getRepository(ExternalRef::class);
        if (null !== $refs->findOneBy(['system' => $connector->getSystem(), 'entityType' => ExternalRef::TYPE_ORDER, 'entityId' => $order->getId()])) {
            return; // уже відправлено (retry після часткового збою)
        }
        try {
            $ref = $connector->pushOrder($this->exports->fromOrder($order));
        } catch (CrmNotSupportedException $e) {
            $this->logger->info('CRM push пропущено: '.$e->getMessage(), ['order' => $order->getNumber()]);

            return;
        }
        if (null !== $ref->externalOrderId) {
            $this->em->persist(new ExternalRef($connector->getSystem(), ExternalRef::TYPE_ORDER, (int) $order->getId(), $ref->externalOrderId));
            $this->em->flush();
        }
        $this->logger->info('CRM push виконано', ['order' => $order->getNumber(), 'crmId' => $ref->externalOrderId]);
    }
}
