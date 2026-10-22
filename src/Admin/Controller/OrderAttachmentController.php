<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Core\Order\Attachment\AttachmentPolicy;
use App\Core\Order\Attachment\AttachmentStorage;
use App\Core\Order\Entity\OrderAttachment;
use App\Core\Security\Entity\User;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Вкладення замовлення менеджеру (картка замовлення в адмінці): файл із приватного сховища як attachment, nosniff, no-store —
 * браузер не рендерить його в контексті адмінки. Кожне скачування — у security_audit (хто, замовлення, файл): це файли покупців.
 */
#[Route(host: '%admin_host%')]
#[WithMonologChannel('security_audit')]
final class OrderAttachmentController extends AbstractController
{
    public function __construct(private readonly AttachmentStorage $storage, private readonly LoggerInterface $logger)
    {
    }

    #[Route('/order-attachment/{id}', name: 'admin_order_attachment', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function download(#[MapEntity] OrderAttachment $attachment): Response
    {
        $path = $this->storage->path($attachment);
        if (null === $path) {
            throw $this->createNotFoundException('Файл уже видалено за строком зберігання');
        }
        $user = $this->getUser();
        $this->logger->info('order.attachment.download', [
            'user_id' => $user instanceof User ? $user->getId() : null,
            'order' => $attachment->getOrder()->getNumber(),
            'attachment_id' => $attachment->getId(),
            'size' => $attachment->getSize(),
        ]);

        $ext = AttachmentPolicy::ALLOWED[$attachment->getMime()] ?? 'bin';
        // ім'я для браузера: <номер>-<id>-<назва без шляхів і службових символів>.<ext за MIME>; ASCII-запасне — лише [A-Za-z0-9._-]
        $base = mb_substr(trim((string) preg_replace('#[\\\\/:*?"<>|\x00-\x1F%]+#u', '_', pathinfo($attachment->getOriginalName(), \PATHINFO_FILENAME)), '_. '), 0, 60);
        $prefix = $attachment->getOrder()->getNumber().'-'.$attachment->getId().'-';
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $base), '_.');
        $response = new BinaryFileResponse($path, 200, [], false, null, false, false);
        $response->headers->set('Content-Type', $attachment->getMime());
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $prefix.('' === $base ? 'file' : $base).'.'.$ext, $prefix.('' === $ascii ? 'file' : $ascii).'.'.$ext));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }
}
