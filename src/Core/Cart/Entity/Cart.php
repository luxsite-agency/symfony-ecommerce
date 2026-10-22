<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Cart\Entity;

use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Catalog\QuantityRules;
use App\Core\Customer\Entity\Customer;
use App\Core\Shared\Quantity\Quantity;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * DB-кошик (ADR D4): створюється лінива на перший add-to-cart, у cookie — bearer-токен, у БД — його hash.
 * Ціни в рядках не зберігаються — CartCalculator рахує щоразу через PriceResolver. Усі рядки в валюті кошика.
 * Кошик покупця (E12 PR-3): один активний на покупця (unique customer_id, FK SET NULL), резолвер знаходить його за
 * покупцем, а не за cookie; гостьовий кошик при вході зливається в нього (CartMerger) і токен ротується.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cart')]
#[ORM\UniqueConstraint(name: 'uniq_cart_token_hash', columns: ['token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_cart_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_cart_expires', columns: ['expires_at'])]
#[ORM\HasLifecycleCallbacks]
class Cart
{
    public const string TTL = '+30 days';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\Column]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<int, CartItem> */
    #[ORM\OneToMany(targetEntity: CartItem::class, mappedBy: 'cart', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $items;

    public function __construct(string $tokenHash, string $currency)
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $tokenHash)) {
            throw new InvalidArgumentException('Очікується sha256-хеш токена');
        }
        $this->tokenHash = $tokenHash;
        $this->currency = strtoupper($currency);
        $this->items = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new DateTimeImmutable();
        $this->expiresAt = new DateTimeImmutable(self::TTL);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function getCustomerId(): ?int
    {
        return $this->customer?->getId();
    }

    /** Кошик стає кошиком покупця (вхід без власного кошика або новий кошик залогіненого). */
    public function assignTo(Customer $customer): void
    {
        $this->customer = $customer;
        $this->touchExpiry();
    }

    /** Новий bearer-токен після входу (cart fixation: старий токен з гостьової сесії більше не відкриває кошик). */
    public function rotateToken(string $tokenHash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $tokenHash)) {
            throw new InvalidArgumentException('Очікується sha256-хеш токена');
        }
        $this->tokenHash = $tokenHash;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt < ($now ?? new DateTimeImmutable());
    }

    public function touchExpiry(): void
    {
        $this->expiresAt = new DateTimeImmutable(self::TTL);
    }

    /** @return Collection<int, CartItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    /** Рядок варіанта з тією самою конфігурацією опцій (ключ снепшота; null — без опцій). */
    public function findItem(ProductVariant $variant, ?string $optionsKey = null): ?CartItem
    {
        return $this->items->findFirst(static fn (int $_, CartItem $i): bool => $i->getVariant() === $variant && $i->getOptionsKey() === $optionsKey);
    }

    public function findItemById(int $id): ?CartItem
    {
        return $this->items->findFirst(static fn (int $_, CartItem $i): bool => $i->getId() === $id);
    }

    /**
     * Той самий варіант з тією самою конфігурацією опцій — додає кількість; інша конфігурація — окремий рядок.
     * Валюта варіанта має збігатися з валютою кошика.
     *
     * @param array<string, mixed>|null $optionsSnapshot OptionConfiguration::$snapshot (ADR Rev 9)
     */
    public function addItem(ProductVariant $variant, string $quantity, ?array $optionsSnapshot = null): CartItem
    {
        if ($variant->getCurrency() !== $this->currency) {
            throw new CatalogInvariantViolation(\sprintf('Кошик у валюті %s, варіант — %s', $this->currency, $variant->getCurrency()));
        }
        if (!$variant->isActive() || !$variant->getProduct()->isActive()) {
            throw new CatalogInvariantViolation('Товар недоступний для замовлення');
        }
        if (!$variant->isOrderable()) {
            throw new CatalogInvariantViolation('Ціна цього товару за запитом: надішліть запит на сторінці товару');
        }
        // кількість за правилом варіанта (≥ min, кратна step) — і для нового рядка, і для злиття (консиліум PR #6 п. 5)
        $quantity = Quantity::positive($quantity);
        $item = $this->findItem($variant, CartItem::optionsKeyOf($optionsSnapshot));
        if (null !== $item) {
            $item->setQuantity(bcadd($item->getQuantity(), $quantity, 3));
        } else {
            $item = new CartItem($this, $variant, QuantityRules::assertAllowed($variant, $quantity), $optionsSnapshot);
            $item->setPosition($this->items->count());
            $this->items->add($item);
        }
        $this->touchExpiry();

        return $item;
    }

    public function removeItem(CartItem $item): void
    {
        $this->items->removeElement($item);
        $this->touchExpiry();
    }

    public function clear(): void
    {
        $this->items->clear();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
