<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Dto;

use Symfony\Component\Serializer\Attribute\DiscriminatorMap;

/**
 * Операція пакета змін (E15 PR-3). JSON: {"op": "<тип>", …поля, "expect": {…}}. expect — поточні значення всіх полів, які
 * операція змінює (compare-and-set): без збігу пакет не створюється і не застосовується. Операцій над залишками, замовленнями,
 * покупцями немає і не буде (рішення власника 11.10: set_stock виключено).
 */
#[DiscriminatorMap(typeProperty: 'op', mapping: [
    SetVariantPrice::TYPE => SetVariantPrice::class,
    SetPriceTier::TYPE => SetPriceTier::class,
    RemovePriceTier::TYPE => RemovePriceTier::class,
    SetTranslation::TYPE => SetTranslation::class,
    SetProductFlags::TYPE => SetProductFlags::class,
    SetProductCategories::TYPE => SetProductCategories::class,
])]
abstract readonly class Operation
{
    /** Цінові операції (скоуп prices:write, добовий ліміт, лист відповідальним за ціни) — єдиний перелік для всіх споживачів */
    public const array PRICE_TYPES = [SetVariantPrice::TYPE, SetPriceTier::TYPE, RemovePriceTier::TYPE];
    public const string PRICE_PATTERN = '/^\d{1,9}(\.\d{1,2})?$/';
    public const string QUANTITY_PATTERN = '/^\d{1,9}(\.\d{1,3})?$/';

    /** @param array<string, mixed> $expect */
    public function __construct(public array $expect)
    {
    }

    abstract public function type(): string;

    /** Цінова операція: рахується в mass_price і в добовому ліміті на токен. */
    public function isPrice(): bool
    {
        return false;
    }

    /** @return array<string, mixed> нормалізоване подання для збереження в пакеті (без розв'язаних id — їх додає планувальник) */
    abstract public function toArray(): array;
}
