<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Integration\Core\Order;

use App\Core\Order\Attachment\AttachmentStorage;
use App\Core\Order\Entity\Order;
use App\Core\Order\Entity\OrderAttachment;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\PaymentMethod;
use App\Core\Order\Enum\ShippingMethod;
use App\Core\Order\Message\PurgeOrderAttachments;
use App\Core\Order\MessageHandler\PurgeOrderAttachmentsHandler;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/** Ретенція: файл старший за app.order_attachment_retention_days стирається, рядок лишається з purged_at; свіжий — ні. */
final class PurgeOrderAttachmentsTest extends KernelTestCase
{
    public function testPurgesOnlyExpiredFiles(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $storage = static::getContainer()->get(AttachmentStorage::class);
        /** @var int $days */
        $days = static::getContainer()->getParameter('app.order_attachment_retention_days');
        /** @var string $dir */
        $dir = static::getContainer()->getParameter('app.order_attachment_dir');
        $fs = new Filesystem();

        $order = new Order('LEAD-'.bin2hex(random_bytes(3)), OrderSource::Lead, 'uk', 'Олег', '+380671234567', 'UAH', ShippingMethod::ManagerContact, PaymentMethod::ManagerContact);
        $old = new OrderAttachment($order, 'old.pdf', 'application/pdf', 10, bin2hex(random_bytes(16)));
        $old->setUploadedAt(new DateTimeImmutable('-'.($days + 1).' days'));
        $fresh = new OrderAttachment($order, 'fresh.pdf', 'application/pdf', 10, bin2hex(random_bytes(16)));
        $oldPath = $dir.'/'.$old->getUploadedAt()->format('Y/m').'/'.$old->getStoredName();
        $freshPath = $dir.'/'.$fresh->getUploadedAt()->format('Y/m').'/'.$fresh->getStoredName();
        $fs->dumpFile($oldPath, '%PDF-old');
        $fs->dumpFile($freshPath, '%PDF-fresh');
        $em->persist($order);
        $em->flush();
        self::assertSame($oldPath, $storage->path($old));

        $purged = static::getContainer()->get(PurgeOrderAttachmentsHandler::class)(new PurgeOrderAttachments());
        self::assertGreaterThanOrEqual(1, $purged);
        self::assertFileDoesNotExist($oldPath);
        self::assertFileExists($freshPath);
        $em->clear();
        $old = $em->find(OrderAttachment::class, $old->getId());
        $fresh = $em->find(OrderAttachment::class, $fresh->getId());
        self::assertNotNull($old);
        self::assertNotNull($fresh);
        self::assertNotNull($old->getPurgedAt());
        self::assertNull($storage->path($old), 'стертий файл не віддається');
        self::assertNull($fresh->getPurgedAt());

        // повторний прогін нічого не ламає (рядок уже purged)
        static::getContainer()->get(PurgeOrderAttachmentsHandler::class)(new PurgeOrderAttachments());
        self::assertFileExists($freshPath);
    }
}
