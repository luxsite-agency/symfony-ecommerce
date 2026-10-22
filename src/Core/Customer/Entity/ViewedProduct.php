<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Entity;

use App\Core\Catalog\Entity\Product;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Переглянутий товар покупця (E12 PR-10): один рядок на пару покупець × товар, повторний перегляд оновлює viewedAt;
 * RecentlyViewed тримає не більше ліміту останніх. Зникає разом з товаром або покупцем (CASCADE).
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_viewed')]
#[ORM\UniqueConstraint(name: 'uniq_customer_viewed', columns: ['customer_id', 'product_id'])]
class ViewedProduct
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
    private DateTimeImmutable $viewedAt;

    public function __construct(Customer $customer, Product $product)
    {
        $this->customer = $customer;
        $this->product = $product;
        $this->viewedAt = new DateTimeImmutable();
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

    public function getViewedAt(): DateTimeImmutable
    {
        return $this->viewedAt;
    }
}
