<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Entity;

use App\Core\Catalog\Entity\Product;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** Обране покупця (E12 PR-10): товар у списку раз (unique), зникає разом з товаром або покупцем (CASCADE). */
#[ORM\Entity]
#[ORM\Table(name: 'customer_wishlist')]
#[ORM\UniqueConstraint(name: 'uniq_customer_wishlist', columns: ['customer_id', 'product_id'])]
class WishlistItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(Customer $customer, Product $product)
    {
        $this->customer = $customer;
        $this->product = $product;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
