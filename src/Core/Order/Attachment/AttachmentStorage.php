<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Attachment;

use App\Core\Order\Entity\OrderAttachment;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Файли вкладень поза public/: app.order_attachment_dir (var/share/private/attachments — спільний том php і worker,
 * як exports). Шлях — <рік>/<місяць>/<32 hex> без розширення: нічого з цієї теки nginx не віддає, PHP не виконує,
 * зображення не рендеряться (BinaryFileResponse з attachment + nosniff в адмінці).
 */
final class AttachmentStorage
{
    public function __construct(
        #[Autowire('%app.order_attachment_dir%')] private readonly string $dir,
        private readonly Filesystem $fs = new Filesystem(),
    ) {
    }

    /**
     * Переносить завантажений файл у сховище; повертає storedName. $uploadedAt — та сама мить, що пишеться в OrderAttachment:
     * рік/місяць шляху беруться з неї, а не з «зараз» (рев'ю C #105 L2: на межі місяця шлях і метадані розходились би).
     */
    public function store(UploadedFile $file, DateTimeImmutable $uploadedAt): string
    {
        $name = bin2hex(random_bytes(16));
        $sub = $this->dir.'/'.$uploadedAt->format('Y').'/'.$uploadedAt->format('m');
        $this->fs->mkdir($sub, 0750);
        $file->move($sub, $name);
        $this->fs->chmod($sub.'/'.$name, 0640);

        return $name;
    }

    /** Абсолютний шлях або null, якщо файл уже стерто (ретенція) чи його нема. */
    public function path(OrderAttachment $attachment): ?string
    {
        if (null !== $attachment->getPurgedAt()) {
            return null;
        }
        $path = $this->locate($attachment->getStoredName(), $attachment->getUploadedAt()->format('Y'), $attachment->getUploadedAt()->format('m'));

        return is_file($path) ? $path : null;
    }

    public function remove(OrderAttachment $attachment): void
    {
        $path = $this->locate($attachment->getStoredName(), $attachment->getUploadedAt()->format('Y'), $attachment->getUploadedAt()->format('m'));
        if (is_file($path)) {
            $this->fs->remove($path);
        }
    }

    /** Файл, який щойно записали, але транзакція не вдалась. */
    public function discard(string $storedName, DateTimeImmutable $uploadedAt): void
    {
        $path = $this->locate($storedName, $uploadedAt->format('Y'), $uploadedAt->format('m'));
        if (is_file($path)) {
            $this->fs->remove($path);
        }
    }

    private function locate(string $storedName, string $year, string $month): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $storedName) || !preg_match('/^\d{4}$/', $year) || !preg_match('/^\d{2}$/', $month)) {
            throw new InvalidArgumentException('Некоректне ім\'я вкладення');
        }

        return $this->dir.'/'.$year.'/'.$month.'/'.$storedName;
    }
}
