<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shipping\NovaPoshta\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Локальний довідник міст Нової пошти (ADR D9): копія Address.getCities, оновлюється щодня NovaPoshtaDirectorySync.
 * Читається DBAL-ом (NovaPoshtaDirectory); сутність — для схеми й міграцій.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shipping_np_city')]
#[ORM\Index(name: 'idx_shipping_np_city_name', columns: ['name'])]
#[ORM\Index(name: 'idx_shipping_np_city_name_ru', columns: ['name_ru'])]
class NovaPoshtaCity
{
    #[ORM\Id]
    #[ORM\Column(length: 36, options: ['fixed' => true])]
    private string $ref;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(length: 190)]
    private string $nameRu;

    /** Область (AreaDescription) — щоб розрізняти однойменні населені пункти. */
    #[ORM\Column(length: 120)]
    private string $area;

    /** Тип населеного пункту (SettlementTypeDescription): місто, село, смт… */
    #[ORM\Column(length: 60)]
    private string $settlementType;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $ref, string $name, string $nameRu, string $area, string $settlementType)
    {
        $this->ref = $ref;
        $this->name = $name;
        $this->nameRu = $nameRu;
        $this->area = $area;
        $this->settlementType = $settlementType;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getRef(): string
    {
        return $this->ref;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNameRu(): string
    {
        return $this->nameRu;
    }

    public function getArea(): string
    {
        return $this->area;
    }

    public function getSettlementType(): string
    {
        return $this->settlementType;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
