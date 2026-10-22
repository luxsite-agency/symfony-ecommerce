<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/** {"op":"remove_price_tier","variant":…,"min_quantity":"10","customer_group":null,"expect":{"price":"1150.00"}} */
final readonly class RemovePriceTier extends Operation
{
    public const string TYPE = 'remove_price_tier';

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
        return ['op' => self::TYPE, 'variant' => $this->variant, 'min_quantity' => $this->minQuantity, 'customer_group' => $this->customerGroup, 'expect' => $this->expect];
    }
}
