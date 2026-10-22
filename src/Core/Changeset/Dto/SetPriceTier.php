<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * {"op":"set_price_tier","variant":…,"min_quantity":"10","customer_group":"wholesale"|null,"price":"1100.00","expect":{"price":"…"|null}}
 * expect.price null = порога ще нема (буде створено); інакше — поточна ціна порога (буде змінено).
 */
final readonly class SetPriceTier extends Operation
{
    public const string TYPE = 'set_price_tier';

    /** @param array<string, mixed> $expect */
    public function __construct(
        #[Assert\NotBlank(message: 'changeset.operation.variant.not_blank')]
        public int|string $variant,
        #[SerializedName('min_quantity')]
        #[Assert\Regex(self::QUANTITY_PATTERN, message: 'changeset.operation.min_quantity.format')]
        public string $minQuantity,
        #[SerializedName('customer_group')]
        #[Assert\Length(max: 64)]
        public ?string $customerGroup,
        #[Assert\Regex(self::PRICE_PATTERN, message: 'changeset.operation.price.format')]
        public string $price,
        array $expect,
    ) {
        parent::__construct($expect);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function isPrice(): bool
    {
        return true;
    }

    public function toArray(): array
    {
        return ['op' => self::TYPE, 'variant' => $this->variant, 'min_quantity' => $this->minQuantity, 'customer_group' => $this->customerGroup, 'price' => $this->price, 'expect' => $this->expect];
    }
}
