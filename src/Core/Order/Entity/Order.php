<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order\Entity;

use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\OrderStatus;
use App\Core\Order\Enum\PaymentMethod;
use App\Core\Order\Enum\PaymentStatus;
use App\Core\Order\Enum\ShippingMethod;
use App\Core\Order\Repository\OrderRepository;
use App\Core\Order\ShippingPoint;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Замовлення (ADR D6/D8): номер YYMM-NNNNNN з глобального лічильника, стан через Workflow «order» (status — рядок,
 * бо MethodMarkingStore працює з рядками), статус оплати окремо, снепшоти покупця/доставки/оплати, окремі totals.
 * Успішна сторінка — за opaque successToken; ідемпотентність повторного submit — unique idempotencyKey.
 * Покупець (E12/E13): customer — FK SET NULL (видалення акаунта лишає замовлення), customerGroupCode — снепшот групи,
 * за якою рахувались ціни на момент оформлення (група покупця може змінитись пізніше). Гостьове замовлення покупець
 * прив'язує сам з кабінету за підтвердженим email (E12 PR-2): claimedAt/claimMethod — журнал прив'язки.
 */
#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'sales_order')]
#[ORM\UniqueConstraint(name: 'uniq_sales_order_number', columns: ['number'])]
#[ORM\UniqueConstraint(name: 'uniq_sales_order_success_token', columns: ['success_token'])]
#[ORM\UniqueConstraint(name: 'uniq_sales_order_idempotency', columns: ['idempotency_key'])]
#[ORM\Index(name: 'idx_sales_order_status_placed', columns: ['status', 'placed_at'])]
#[ORM\Index(name: 'idx_sales_order_placed_at', columns: ['placed_at'])]
#[ORM\Index(name: 'idx_sales_order_phone', columns: ['phone'])]
#[ORM\Index(name: 'idx_sales_order_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_sales_order_email', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $number;

    #[ORM\Column(length: 16)]
    private string $status = 'new';

    #[ORM\Column(length: 16, enumType: PaymentStatus::class)]
    private PaymentStatus $paymentStatus = PaymentStatus::Pending;

    #[ORM\Column(length: 16, enumType: OrderSource::class)]
    private OrderSource $source;

    #[ORM\Column(length: 12)]
    private string $locale;

    #[ORM\Column(length: 120)]
    private string $customerName;

    /** Нормалізований: +380XXXXXXXXX (PhoneNormalizer). */
    #[ORM\Column(length: 20)]
    private string $phone;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    /** Компанія покупця (B2B-заявка /request; у checkout поки не питаємо). */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $company = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(length: 16, enumType: ShippingMethod::class)]
    private ShippingMethod $shippingMethod;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $shippingCity = null;

    /** Відділення НП або адреса кур'єра — текстом (снепшот назви з довідника або те, що ввів покупець). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $shippingAddress = null;

    /**
     * Снепшот обраного відділення з довідника НП (ShippingPoint: refs + назви), Ф3; null — текстовий фолбек.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $shippingPoint = null;

    #[ORM\Column(length: 16, enumType: PaymentMethod::class)]
    private PaymentMethod $paymentMethod;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency;

    // BIGINT: INT обмежував суму 21 474 836,47 UAH — велике B2B-замовлення давало 500 (консиліум PR #6, low)
    #[ORM\Column(type: Types::BIGINT)]
    private int $itemsTotalMinor = 0;

    #[ORM\Column(type: Types::BIGINT)]
    private int $shippingMinor = 0;

    #[ORM\Column(type: Types::BIGINT)]
    private int $totalMinor = 0;

    #[ORM\Column(length: 64)]
    private string $successToken;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $idempotencyKey = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $customerGroupCode = null;

    /** Коли і як гостьове замовлення прив'язано до покупця (null — оформлене залогіненим або ще нічиє). */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $claimedAt = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $claimMethod = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column]
    private DateTimeImmutable $placedAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $completedAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $cancelledAt = null;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<int, OrderItem> */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $items;

    /** @var Collection<int, OrderAttachment> */
    #[ORM\OneToMany(targetEntity: OrderAttachment::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $attachments;

    /** @var Collection<int, OrderStatusHistory> */
    #[ORM\OneToMany(targetEntity: OrderStatusHistory::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $history;

    public function __construct(
        string $number,
        OrderSource $source,
        string $locale,
        string $customerName,
        string $phone,
        string $currency,
        ShippingMethod $shippingMethod,
        PaymentMethod $paymentMethod,
    ) {
        if ('' === trim($customerName) || mb_strlen($customerName) > 120) {
            throw new InvalidArgumentException('Вкажіть ім\'я (до 120 символів)');
        }
        $this->number = $number;
        $this->source = $source;
        $this->locale = $locale;
        $this->customerName = trim($customerName);
        $this->phone = $phone;
        $this->currency = strtoupper($currency);
        $this->shippingMethod = $shippingMethod;
        $this->paymentMethod = $paymentMethod;
        $this->successToken = bin2hex(random_bytes(24));
        $this->placedAt = $this->updatedAt = new DateTimeImmutable();
        $this->items = new ArrayCollection();
        $this->attachments = new ArrayCollection();
        $this->history = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    /** Для MethodMarkingStore (single_state). */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @internal лише Workflow/OrderTransitionService
     *
     * @param array<string, mixed> $context
     */
    public function setStatus(string $status, array $context = []): void
    {
        $this->status = OrderStatus::from($status)->value;
        $now = new DateTimeImmutable();
        match ($this->status) {
            'confirmed' => $this->confirmedAt ??= $now,
            'completed' => $this->completedAt ??= $now,
            'cancelled' => $this->cancelledAt ??= $now,
            default => null,
        };
    }

    public function getStatusEnum(): OrderStatus
    {
        return OrderStatus::from($this->status);
    }

    public function getPaymentStatus(): PaymentStatus
    {
        return $this->paymentStatus;
    }

    public function setPaymentStatus(PaymentStatus $paymentStatus): void
    {
        $this->paymentStatus = $paymentStatus;
    }

    public function getSource(): OrderSource
    {
        return $this->source;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getCustomerName(): string
    {
        return $this->customerName;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $email = null === $email ? null : mb_strtolower(trim($email));
        $this->email = '' === $email ? null : $email;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(?string $company): void
    {
        $company = null === $company ? null : trim($company);
        $this->company = '' === $company ? null : $company;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): void
    {
        $comment = null === $comment ? null : trim($comment);
        $this->comment = null === $comment || '' === $comment ? null : mb_substr($comment, 0, 2000);
    }

    public function getShippingMethod(): ShippingMethod
    {
        return $this->shippingMethod;
    }

    public function getShippingCity(): ?string
    {
        return $this->shippingCity;
    }

    public function getShippingAddress(): ?string
    {
        return $this->shippingAddress;
    }

    public function setShipping(?string $city, ?string $address, ?ShippingPoint $point = null): void
    {
        if (null !== $point) {
            // довідник НП: місто й відділення — з снепшота, текст покупця ігнорується
            $city = '' !== $point->cityName ? $point->cityName : $city;
            $address = $point->warehouseName;
        }
        $this->shippingCity = null === $city || '' === trim($city) ? null : mb_substr(trim($city), 0, 120);
        $this->shippingAddress = null === $address || '' === trim($address) ? null : mb_substr(trim($address), 0, 255);
        $this->shippingPoint = $point?->toArray();
    }

    public function getShippingPoint(): ?ShippingPoint
    {
        return null === $this->shippingPoint ? null : ShippingPoint::fromArray($this->shippingPoint);
    }

    /** Для адмінки: «Нова пошта, відділення №12 (ref …)» або порожньо. */
    public function getShippingPointSummary(): ?string
    {
        $point = $this->getShippingPoint();

        return null === $point ? null : \sprintf('%s: %s [%s]', $point->provider, $point->warehouseName, $point->warehouseRef);
    }

    public function getPaymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getItemsTotal(): Money
    {
        return Money::of($this->itemsTotalMinor, $this->currency);
    }

    public function getShipping(): Money
    {
        return Money::of($this->shippingMinor, $this->currency);
    }

    public function getTotal(): Money
    {
        return Money::of($this->totalMinor, $this->currency);
    }

    /** Перерахунок сум із рядків (доставка — окремо, у v1 = 0: тарифи Ф3). */
    public function recalculateTotals(?Money $shipping = null): void
    {
        $items = Money::zero($this->currency);
        foreach ($this->items as $item) {
            if (null === $item->getParent()) {
                $items = $items->add($item->getLineTotal());
            }
        }
        $shipping ??= Money::zero($this->currency);
        $this->itemsTotalMinor = $items->amount;
        $this->shippingMinor = $shipping->amount;
        $this->totalMinor = $items->add($shipping)->amount;
    }

    public function getSuccessToken(): string
    {
        return $this->successToken;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(?string $key): void
    {
        $this->idempotencyKey = null === $key || '' === $key ? null : mb_substr($key, 0, 64);
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    /** Код групи, за якою рахувались ціни замовлення; null — роздріб. */
    public function getCustomerGroupCode(): ?string
    {
        return $this->customerGroupCode;
    }

    /** Покупець з токена і група, за якою PlaceOrder порахував ціни (не обов'язково поточна група покупця). */
    public function setCustomer(?Customer $customer, ?CustomerGroup $group): void
    {
        $this->customer = $customer;
        $this->customerGroupCode = $group?->getCode();
    }

    /**
     * Прив'язка гостьового замовлення до покупця (E12 PR-2): лише нічиє; група не змінюється — ціни вже пораховані.
     *
     * @throws InvalidArgumentException замовлення вже має покупця
     */
    public function claim(Customer $customer, string $method): void
    {
        if (null !== $this->customer) {
            throw new InvalidArgumentException('Замовлення вже прив\'язане до покупця');
        }
        $this->customer = $customer;
        $this->claimedAt = new DateTimeImmutable();
        $this->claimMethod = $method;
    }

    public function getClaimedAt(): ?DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function getClaimMethod(): ?string
    {
        return $this->claimMethod;
    }

    public function setIp(?string $ip): void
    {
        $this->ip = $ip;
    }

    public function getPlacedAt(): DateTimeImmutable
    {
        return $this->placedAt;
    }

    public function getConfirmedAt(): ?DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getCancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, OrderItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(OrderItem $item): void
    {
        $item->setPosition($this->items->count());
        $this->items->add($item);
    }

    /** @return Collection<int, OrderAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(OrderAttachment $attachment): void
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
        }
    }

    /** @return Collection<int, OrderStatusHistory> */
    public function getHistory(): Collection
    {
        return $this->history;
    }

    public function addHistory(OrderStatusHistory $entry): void
    {
        $this->history->add($entry);
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->number;
    }
}
