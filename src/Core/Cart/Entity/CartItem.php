<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Cart\Entity;

use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\QuantityRules;
use App\Core\Shared\Quantity\Quantity;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Рядок кошика: варіант × кількість (DECIMAL(12,3) — метри/кг), опційно снепшот обраних опцій (Rev 6/9).
 * Без цін: рахує CartCalculator. Видалений варіант — рядок зникає (CASCADE), кошик показує решту.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cart_item')]
#[ORM\Index(name: 'idx_cart_item_variant', columns: ['variant_id'])]
class CartItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Cart $cart;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProductVariant $variant;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3)]
    private string $quantity;

    /**
     * Снепшот опцій (ADR Rev 9: {schemaVersion, key, selection, groups[], components[], pricing}); null — без опцій.
     * Рахує OptionPriceCalculator; у кошику вибір перераховується за поточним каталогом.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $optionsSnapshot = null;

    #[ORM\Column]
    private int $position = 0;

    /** @internal використовуйте Cart::addItem() */
    /** @param array<string, mixed>|null $optionsSnapshot */
    public function __construct(Cart $cart, ProductVariant $variant, string $quantity, ?array $optionsSnapshot = null)
    {
        $this->cart = $cart;
        $this->variant = $variant;
        $this->quantity = Quantity::positive($quantity);
        $this->optionsSnapshot = $optionsSnapshot;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCart(): Cart
    {
        return $this->cart;
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    /** Кількість за правилом варіанта: ≥ minQuantity і кратна quantityStep (консиліум PR #6 п. 5). */
    public function setQuantity(string $quantity): void
    {
        $this->quantity = QuantityRules::assertAllowed($this->variant, $quantity);
    }

    /** @return array<string, mixed>|null */
    public function getOptionsSnapshot(): ?array
    {
        return $this->optionsSnapshot;
    }

    /** Ключ конфігурації опцій (хеш вибору): різні конфігурації того самого варіанта — різні рядки. */
    public function getOptionsKey(): ?string
    {
        return self::optionsKeyOf($this->optionsSnapshot);
    }

    /** @param array<string, mixed>|null $snapshot */
    public static function optionsKeyOf(?array $snapshot): ?string
    {
        return \is_string($snapshot['key'] ?? null) ? $snapshot['key'] : null;
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
