<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * {"op":"set_variant_price","variant":123|"SKU","price":"1299.00","old_price":"1499.00"|null,"expect":{"price":"…","old_price":null}}
 * old_price — обов'язковий ключ (null = без перекресленої): ціна й перекреслена змінюються парою, як у ProductVariant::setPrice.
 */
final readonly class SetVariantPrice extends Operation
{
    public const string TYPE = 'set_variant_price';

    /** @param array<string, mixed> $expect */
    public function __construct(
        #[Assert\NotBlank(message: 'changeset.operation.variant.not_blank')]
        public int|string $variant,
        #[Assert\Regex(self::PRICE_PATTERN, message: 'changeset.operation.price.format')]
        public string $price,
        #[SerializedName('old_price')]
        #[Assert\Regex(self::PRICE_PATTERN, message: 'changeset.operation.old_price.format')]
        public ?string $oldPrice,
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
        return ['op' => self::TYPE, 'variant' => $this->variant, 'price' => $this->price, 'old_price' => $this->oldPrice, 'expect' => $this->expect];
    }
}
