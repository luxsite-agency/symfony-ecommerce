<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\MessageHandler;

use App\Core\Order\Attachment\AttachmentStorage;
use App\Core\Order\Entity\OrderAttachment;
use App\Core\Order\Message\PurgeOrderAttachments;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Ретенція вкладень покупців: файли, завантажені раніше ніж app.order_attachment_retention_days днів тому, стираються з диска;
 * рядок OrderAttachment лишається з purged_at — менеджер бачить, що файл був, але вмісту вже нема. Пачками по 200.
 */
#[AsMessageHandler]
final class PurgeOrderAttachmentsHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttachmentStorage $storage,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.order_attachment_retention_days%')] private readonly int $days,
    ) {
    }

    public function __invoke(PurgeOrderAttachments $message): int
    {
        $edge = new DateTimeImmutable('-'.$this->days.' days');
        $purged = 0;
        do {
            /** @var list<OrderAttachment> $batch */
            $batch = $this->em->createQuery('SELECT a FROM '.OrderAttachment::class.' a WHERE a.purgedAt IS NULL AND a.uploadedAt < :edge ORDER BY a.id ASC')
                ->setParameter('edge', $edge)->setMaxResults(200)->getResult();
            foreach ($batch as $attachment) {
                $this->storage->remove($attachment);
                $attachment->markPurged();
                ++$purged;
            }
            $this->em->flush();
            $this->em->clear();
        } while (\count($batch) === 200);
        if ($purged > 0) {
            $this->logger->info('Вкладення замовлень стерто за строком зберігання', ['count' => $purged, 'days' => $this->days]);
        }

        return $purged;
    }
}
