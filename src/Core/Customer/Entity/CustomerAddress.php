<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Entity;

use App\Core\Order\Enum\ShippingMethod;
use App\Core\Order\PhoneNormalizer;
use App\Core\Order\ShippingPoint;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Адреса доставки покупця (E12 PR-6, консиліум П2): відділення або поштомат НП зі снепшотом довідника (ShippingPoint)
 * або текстова адреса курʼєра; отримувач і телефон; кілька адрес, одна за замовчуванням. У checkout підставляється,
 * замовлення зберігає власний снепшот (Order::setShipping), тож зміна адреси не змінює минулих замовлень.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_address')]
#[ORM\Index(name: 'idx_customer_address_customer', columns: ['customer_id', 'is_default'])]
#[ORM\HasLifecycleCallbacks]
class CustomerAddress
{
    public const int MAX_PER_CUSTOMER = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    /** Підпис для списку («Дім», «Офіс»); порожньо — місто й адреса. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(length: 120)]
    private string $recipientName;

    /** E.164 */
    #[ORM\Column(length: 20)]
    private string $recipientPhone;

    #[ORM\Column(length: 16, enumType: ShippingMethod::class)]
    private ShippingMethod $shippingMethod;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    /** @var array<string, string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $shippingPoint = null;

    #[ORM\Column]
    private bool $isDefault = false;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->recipientName = $customer->getName();
        $this->recipientPhone = $customer->getPhone() ?? '';
        $this->shippingMethod = ShippingMethod::NovaPoshta;
        $this->createdAt = $this->updatedAt = new DateTimeImmutable();
    }

    /** @throws InvalidArgumentException */
    public function fill(?string $label, string $recipientName, string $recipientPhone, ShippingMethod $method, ?string $city, ?string $address, ?ShippingPoint $point): void
    {
        $recipientName = trim($recipientName);
        if ('' === $recipientName || mb_strlen($recipientName) > 120) {
            throw new InvalidArgumentException('Вкажіть отримувача (до 120 символів)');
        }
        $this->label = null === $label || '' === trim($label) ? null : mb_substr(trim($label), 0, 60);
        $this->recipientName = $recipientName;
        $this->recipientPhone = PhoneNormalizer::normalize($recipientPhone);
        $this->shippingMethod = $method;
        if (null !== $point) {
            $city = '' !== $point->cityName ? $point->cityName : $city;
            $address = $point->warehouseName;
        }
        $this->city = null === $city || '' === trim($city) ? null : mb_substr(trim($city), 0, 120);
        $this->address = null === $address || '' === trim($address) ? null : mb_substr(trim($address), 0, 255);
        $this->shippingPoint = $point?->toArray();
        if (ShippingMethod::Pickup !== $method && ShippingMethod::ManagerContact !== $method && null === $this->address) {
            throw new InvalidArgumentException('Вкажіть відділення або адресу');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getRecipientName(): string
    {
        return $this->recipientName;
    }

    public function getRecipientPhone(): string
    {
        return $this->recipientPhone;
    }

    public function getShippingMethod(): ShippingMethod
    {
        return $this->shippingMethod;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function getShippingPoint(): ?ShippingPoint
    {
        return null === $this->shippingPoint ? null : ShippingPoint::fromArray($this->shippingPoint);
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    /** @internal CustomerAddresses::makeDefault знімає прапорець з решти */
    public function setDefault(bool $default): void
    {
        $this->isDefault = $default;
    }

    /** Підпис у списку й у виборі на checkout. */
    public function summary(): string
    {
        $parts = array_filter([$this->label, $this->city, $this->address]);

        return implode(', ', $parts);
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
