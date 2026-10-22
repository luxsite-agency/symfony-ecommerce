<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Entity;

use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Рядок замовлення — снепшот на момент оформлення: sku, назва, ціна за одиницю, кількість, сума; варіант —
 * посилання (SET NULL при видаленні). parent — для компонентів набору (Rev 6): дочірні рядки зі своїм SKU
 * для складу/CRM, у суму замовлення входять лише головні. reservedStock — скільки списано (повертається при cancel).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_order_item')]
#[ORM\Index(name: 'idx_sales_order_item_variant', columns: ['variant_id'])]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ProductVariant $variant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?OrderItem $parent = null;

    #[ORM\Column(length: 64)]
    private string $sku;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::BIGINT)]
    private int $unitPriceMinor;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3)]
    private string $quantity;

    #[ORM\Column(type: Types::BIGINT)]
    private int $lineTotalMinor;

    /** Ціна до акцій на момент оформлення (Ф3), null — знижки не було. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $listPriceMinor = null;

    /** Назви акцій, що спрацювали (снепшот для менеджера й CRM). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $promotionName = null;

    /** Скільки залишку списано при оформленні (0 — політика без обліку). */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3)]
    private string $reservedStock = '0.000';

    /** @var array<string, mixed>|null снепшот опцій (Rev 9) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $optionsSnapshot = null;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(Order $order, ProductVariant $variant, string $name, Money $unitPrice, string $quantity, string $reservedStock = '0')
    {
        if ($unitPrice->currency !== $order->getCurrency()) {
            throw new InvalidArgumentException('Валюта рядка має збігатися з валютою замовлення');
        }
        $this->order = $order;
        $this->variant = $variant;
        $this->sku = $variant->getSku();
        $this->name = mb_substr(trim($name) ?: $variant->getSku(), 0, 255);
        $this->unitPriceMinor = $unitPrice->amount;
        $this->quantity = Quantity::positive($quantity);
        $this->lineTotalMinor = $unitPrice->multiplyByDecimal($this->quantity)->amount;
        $this->reservedStock = Quantity::nonNegative($reservedStock);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getVariant(): ?ProductVariant
    {
        return $this->variant;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): void
    {
        $this->parent = $parent;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUnitPrice(): Money
    {
        return Money::of($this->unitPriceMinor, $this->order->getCurrency());
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    /** Зафіксувати знижку акції: ціна до акції та назва; unitPrice уже знижена. */
    public function applyDiscount(Money $listPrice, string $promotionName): void
    {
        if ($listPrice->currency !== $this->order->getCurrency() || $listPrice->amount < $this->unitPriceMinor) {
            throw new InvalidArgumentException('Ціна до акції має бути в валюті замовлення і не меншою за ціну рядка');
        }
        $this->listPriceMinor = $listPrice->amount;
        $this->promotionName = mb_substr(trim($promotionName), 0, 255) ?: null;
    }

    public function getListPrice(): ?Money
    {
        return null === $this->listPriceMinor ? null : Money::of($this->listPriceMinor, $this->order->getCurrency());
    }

    /** Знижка за одиницю або null. */
    public function getDiscount(): ?Money
    {
        $list = $this->getListPrice();

        return null === $list ? null : $list->subtract($this->getUnitPrice());
    }

    public function getPromotionName(): ?string
    {
        return $this->promotionName;
    }

    public function getLineTotal(): Money
    {
        return Money::of($this->lineTotalMinor, $this->order->getCurrency());
    }

    public function getReservedStock(): string
    {
        return $this->reservedStock;
    }

    /** @internal після повернення залишку при скасуванні */
    public function markStockReleased(): void
    {
        $this->reservedStock = '0.000';
    }

    /** @return array<string, mixed>|null */
    public function getOptionsSnapshot(): ?array
    {
        return $this->optionsSnapshot;
    }

    /**
     * Фіксація опцій у момент оформлення (OptionPriceCalculator; у кошику вибір перераховується, тут — ні).
     *
     * @param array<string, mixed>|null $snapshot
     */
    public function setOptionsSnapshot(?array $snapshot): void
    {
        $this->optionsSnapshot = $snapshot;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
