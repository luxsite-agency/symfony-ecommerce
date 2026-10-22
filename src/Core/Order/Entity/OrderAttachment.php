<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Файл, прикладений покупцем до замовлення (заявка /request; далі — рецепти E16). Сам файл лежить поза public/
 * (AttachmentStorage: app.order_attachment_dir/<рік>/<місяць>/<storedName>, без розширення, 0640); у БД — лише метадані.
 * Після строку зберігання файл стирається, рядок лишається з purgedAt — менеджер бачить, що вкладення було.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_order_attachment')]
#[ORM\Index(columns: ['uploaded_at'], name: 'idx_order_attachment_uploaded')]
class OrderAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    /** Назва файлу в покупця (лише для показу; у шляху не бере участі). */
    #[ORM\Column(length: 200)]
    private string $originalName;

    /** MIME за вмістом (AttachmentPolicy), не за розширенням. */
    #[ORM\Column(length: 64)]
    private string $mime;

    #[ORM\Column]
    private int $size;

    /** Випадкове ім'я у сховищі: 32 hex-символи. */
    #[ORM\Column(length: 32, unique: true)]
    private string $storedName;

    #[ORM\Column]
    private DateTimeImmutable $uploadedAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $purgedAt = null;

    /** $uploadedAt — та сама мить, з якої AttachmentStorage::store() узяв рік/місяць шляху. */
    public function __construct(Order $order, string $originalName, string $mime, int $size, string $storedName, ?DateTimeImmutable $uploadedAt = null)
    {
        $this->order = $order;
        $this->originalName = mb_substr($originalName, 0, 200);
        $this->mime = $mime;
        $this->size = $size;
        $this->storedName = $storedName;
        $this->uploadedAt = $uploadedAt ?? new DateTimeImmutable();
        $order->addAttachment($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getStoredName(): string
    {
        return $this->storedName;
    }

    public function getUploadedAt(): DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function getPurgedAt(): ?DateTimeImmutable
    {
        return $this->purgedAt;
    }

    public function markPurged(?DateTimeImmutable $at = null): void
    {
        $this->purgedAt = $at ?? new DateTimeImmutable();
    }

    /** Лише для тестів ретенції. */
    public function setUploadedAt(DateTimeImmutable $at): void
    {
        $this->uploadedAt = $at;
    }
}
