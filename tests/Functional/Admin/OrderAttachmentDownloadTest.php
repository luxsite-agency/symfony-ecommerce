<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Order\Entity\Order;
use App\Core\Order\Entity\OrderAttachment;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\PaymentMethod;
use App\Core\Order\Enum\ShippingMethod;
use App\Core\Security\Enum\UserRole;
use Symfony\Component\Filesystem\Filesystem;

/** Вкладення покупця віддається лише менеджеру/адміну, як attachment з nosniff і no-store; після ретенції — 404. */
final class OrderAttachmentDownloadTest extends AdminWebTestCase
{
    public function testDownloadIsRestrictedAndSafe(): void
    {
        $em = $this->em();
        $order = new Order('LEAD-'.bin2hex(random_bytes(3)), OrderSource::Lead, 'uk', 'Олег', '+380671234567', 'UAH', ShippingMethod::ManagerContact, PaymentMethod::ManagerContact);
        $order->setCompany('ТОВ «Склад»');
        $attachment = new OrderAttachment($order, '../../план <складу>.pdf', 'application/pdf', 20, bin2hex(random_bytes(16)));
        /** @var string $dir */
        $dir = static::getContainer()->getParameter('app.order_attachment_dir');
        $path = $dir.'/'.date('Y').'/'.date('m').'/'.$attachment->getStoredName();
        (new Filesystem())->dumpFile($path, "%PDF-1.4\n%%EOF\n");
        $em->persist($order);
        $em->flush();
        $url = $this->adminUrl('/order-attachment/'.$attachment->getId());

        $this->client->request('GET', $url);
        self::assertResponseRedirects(); // анонім → логін адмінки

        $this->loginAs(UserRole::Manager, 'manager-att@example.test');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment;', $disposition);
        self::assertStringNotContainsString('..', $disposition);
        self::assertStringNotContainsString('<', $disposition);
        self::assertStringContainsString('filename='.$order->getNumber().'-'.$attachment->getId().'-file.pdf;', $disposition, 'ASCII-запасне: кирилиця й службові символи не лишають нічого → file');
        self::assertStringContainsString("filename*=utf-8''".$order->getNumber().'-'.$attachment->getId().'-'.rawurlencode('план _складу').'.pdf', $disposition, 'UTF-8 назва без <>, шляхів і країв _');
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame("%PDF-1.4\n%%EOF\n", (string) file_get_contents($path));

        // картка замовлення показує вкладення з посиланням
        $crawler = $this->client->request('GET', $this->adminUrl('/order/'.$order->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('план <складу>.pdf', $crawler->filter('[data-attachments]')->text());
        self::assertCount(1, $crawler->filter('[data-attachments] a[href$="/order-attachment/'.$attachment->getId().'"]'));

        // після ретенції файл стерто — 404, рядок з позначкою
        // після запитів ядро перезавантажено і старий EM скинуто — працюємо зі свіжим
        $purged = $this->em()->find(OrderAttachment::class, $attachment->getId());
        self::assertNotNull($purged);
        $purged->markPurged();
        $this->em()->flush();
        unlink($path);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
        $crawler = $this->client->request('GET', $this->adminUrl('/order/'.$order->getId()));
        self::assertCount(0, $crawler->filter('[data-attachments] a'));
        self::assertStringContainsString('стерто', $crawler->filter('[data-attachments]')->text());
    }
}
