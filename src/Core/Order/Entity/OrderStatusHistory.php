<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** Історія переходів (ADR D6): хто (email користувача або system), звідки → куди, коментар, коли. */
#[ORM\Entity]
#[ORM\Table(name: 'sales_order_status_history')]
class OrderStatusHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'history')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $fromStatus;

    #[ORM\Column(length: 16)]
    private string $toStatus;

    #[ORM\Column(length: 32)]
    private string $transition;

    #[ORM\Column(length: 180)]
    private string $actor;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $comment;

    #[ORM\Column]
    private DateTimeImmutable $at;

    public function __construct(Order $order, ?string $from, string $to, string $transition, string $actor, ?string $comment = null)
    {
        $this->order = $order;
        $this->fromStatus = $from;
        $this->toStatus = $to;
        $this->transition = $transition;
        $this->actor = mb_substr($actor, 0, 180);
        $this->comment = null === $comment || '' === trim($comment) ? null : mb_substr(trim($comment), 0, 500);
        $this->at = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getFromStatus(): ?string
    {
        return $this->fromStatus;
    }

    public function getToStatus(): string
    {
        return $this->toStatus;
    }

    public function getTransition(): string
    {
        return $this->transition;
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getAt(): DateTimeImmutable
    {
        return $this->at;
    }
}
