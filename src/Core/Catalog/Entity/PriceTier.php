<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Shared\Money\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ціна варіанта від N одиниць, опційно лише для групи клієнтів (ADR Rev 7). Створюється через ProductVariant::addPriceTier().
 * Унікальність (variant, group, minQuantity) для group = NULL MySQL не гарантує — її тримає addPriceTier().
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_price_tier')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_price_tier', columns: ['variant_id', 'customer_group_id', 'min_quantity'])]
class PriceTier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'priceTiers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProductVariant $variant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?CustomerGroup $customerGroup;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3)]
    private string $minQuantity;

    #[ORM\Column]
    private int $priceMinor;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency;

    /** @internal використовуйте ProductVariant::addPriceTier() */
    public function __construct(ProductVariant $variant, string $minQuantity, Money $price, ?CustomerGroup $customerGroup)
    {
        if ($price->isNegative()) {
            throw new CatalogInvariantViolation('Ціна порога не може бути від\'ємною', 'error.price_tier.negative_price');
        }
        $this->variant = $variant;
        $this->minQuantity = $minQuantity;
        $this->priceMinor = $price->amount;
        $this->currency = $price->currency;
        $this->customerGroup = $customerGroup;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getCustomerGroup(): ?CustomerGroup
    {
        return $this->customerGroup;
    }

    public function getMinQuantity(): string
    {
        return $this->minQuantity;
    }

    public function getPrice(): Money
    {
        return Money::of($this->priceMinor, $this->currency);
    }

    /** Нова ціна порога у валюті варіанта (пакети змін E15 PR-3): той самий інваріант, що в конструкторі. */
    public function changePrice(Money $price): void
    {
        if ($price->currency !== $this->currency) {
            throw new CatalogInvariantViolation(\sprintf('Валюта порога %s, а не %s', $this->currency, $price->currency), 'error.price_tier.currency', ['expected' => $this->currency, 'actual' => $price->currency]);
        }
        if ($price->isNegative()) {
            throw new CatalogInvariantViolation('Ціна порога не може бути від\'ємною', 'error.price_tier.negative_price');
        }
        $this->priceMinor = $price->amount;
    }
}
