<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shipping\NovaPoshta\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Локальний довідник відділень і поштоматів Нової пошти (Address.getWarehouses). Читається DBAL-ом. */
#[ORM\Entity]
#[ORM\Table(name: 'shipping_np_warehouse')]
#[ORM\Index(name: 'idx_shipping_np_warehouse_city', columns: ['city_ref', 'active', 'type'])]
class NovaPoshtaWarehouse
{
    public const string TYPE_BRANCH = 'branch';
    public const string TYPE_POSTOMAT = 'postomat';

    #[ORM\Id]
    #[ORM\Column(length: 36, options: ['fixed' => true])]
    private string $ref;

    #[ORM\Column(length: 36, options: ['fixed' => true])]
    private string $cityRef;

    #[ORM\Column(length: 20)]
    private string $number;

    /** Повний опис (Description): «Відділення №12 (до 30 кг): вул. …». */
    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $nameRu;

    #[ORM\Column(length: 255)]
    private string $shortAddress;

    /** branch | postomat | store (CategoryOfWarehouse у нижньому регістрі). */
    #[ORM\Column(length: 20)]
    private string $type;

    /** WarehouseStatus = Working. */
    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $ref, string $cityRef, string $number, string $name, string $nameRu, string $shortAddress, string $type, bool $active = true)
    {
        $this->ref = $ref;
        $this->cityRef = $cityRef;
        $this->number = $number;
        $this->name = $name;
        $this->nameRu = $nameRu;
        $this->shortAddress = $shortAddress;
        $this->type = $type;
        $this->active = $active;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getRef(): string
    {
        return $this->ref;
    }

    public function getCityRef(): string
    {
        return $this->cityRef;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNameRu(): string
    {
        return $this->nameRu;
    }

    public function getShortAddress(): string
    {
        return $this->shortAddress;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
