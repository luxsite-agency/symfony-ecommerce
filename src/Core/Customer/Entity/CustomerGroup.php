<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Entity;

use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;
use App\Core\Shared\Text\MachineCode;
use App\Core\Shared\Translation\TranslatableInvalidArgumentException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Група клієнтів для цін (роздріб / опт / дилер, ADR Rev 7). Назва — службова, для адмінки.
 * B2B-мінімум (E13 PR-7): мінімальна сума (у валюті магазину, в копійках) і/або сумарна кількість одиниць замовлення;
 * перевіряє PlaceOrder для checkout і швидкого замовлення, кошик і checkout показують, скільки бракує.
 * exactStock: покупцям групи картка й лістинг показують точний залишок і термін постачання замість «в наявності».
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_group')]
#[ORM\UniqueConstraint(name: 'uniq_customer_group_code', columns: ['code'])]
#[UniqueEntity(fields: ['code'], message: 'customer.customer_group.code.unique')]
class CustomerGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank(message: 'customer.customer_group.code.not_blank')]
    private string $code;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'customer.customer_group.name.not_blank')]
    private string $name;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(nullable: true)]
    private ?int $minOrderAmountMinor = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 3, nullable: true)]
    private ?string $minOrderQuantity = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $exactStock = false;

    public function __construct(string $code, string $name)
    {
        $this->code = MachineCode::assertValid($code);
        $this->rename($name);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function changeCode(string $code): void
    {
        $this->code = MachineCode::assertValid($code);
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 255) {
            throw new TranslatableInvalidArgumentException('Назва групи має бути непорожньою і не довшою за 255 символів', 'error.customer_group.name_length');
        }
        $this->name = $name;
    }

    /** Та сама група: за id (після $em->clear() один рядок БД — різні екземпляри), для незбережених — за ідентичністю. */
    public function is(self $other): bool
    {
        return $this === $other || (null !== $this->id && $this->id === $other->getId());
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    /** Мінімальна сума замовлення для групи у валюті магазину; null — без мінімуму. */
    public function getMinOrderAmount(string $currency): ?Money
    {
        return null === $this->minOrderAmountMinor ? null : Money::of($this->minOrderAmountMinor, $currency);
    }

    public function getMinOrderAmountMinor(): ?int
    {
        return $this->minOrderAmountMinor;
    }

    /** Для списку в адмінці: сума в основних одиницях валюти рядком. */
    public function getMinOrderAmountDecimal(): ?string
    {
        return null === $this->minOrderAmountMinor ? null : bcdiv((string) $this->minOrderAmountMinor, '100', 2);
    }

    public function setMinOrderAmount(?Money $amount): void
    {
        if (null !== $amount && !$amount->isPositive()) {
            throw new TranslatableInvalidArgumentException('Мінімальна сума замовлення має бути більшою за нуль', 'error.customer_group.min_order_positive');
        }
        $this->minOrderAmountMinor = $amount?->amount;
    }

    /** Мінімальна сумарна кількість одиниць у замовленні; null — без мінімуму. */
    public function getMinOrderQuantity(): ?string
    {
        return null === $this->minOrderQuantity ? null : Quantity::normalize($this->minOrderQuantity);
    }

    public function setMinOrderQuantity(?string $quantity): void
    {
        $this->minOrderQuantity = null === $quantity || '' === trim($quantity) ? null : Quantity::positive($quantity);
    }

    public function showsExactStock(): bool
    {
        return $this->exactStock;
    }

    public function setExactStock(bool $exactStock): void
    {
        $this->exactStock = $exactStock;
    }
}
