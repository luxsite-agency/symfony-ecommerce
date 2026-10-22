<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** {"op":"set_product_flags","product":7|"SKU","active":false,"featured":true,"expect":{"active":true,"featured":false}} — опущений прапорець не чіпається. */
final readonly class SetProductFlags extends Operation
{
    public const string TYPE = 'set_product_flags';

    /** @param array<string, mixed> $expect */
    public function __construct(
        #[Assert\NotBlank(message: 'changeset.operation.product.not_blank')]
        public int|string $product,
        array $expect,
        public ?bool $active = null,
        public ?bool $featured = null,
    ) {
        parent::__construct($expect);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    /** @return array<string, bool> лише задані прапорці */
    public function flags(): array
    {
        return array_filter(['active' => $this->active, 'featured' => $this->featured], static fn (?bool $v): bool => null !== $v);
    }

    public function toArray(): array
    {
        return ['op' => self::TYPE, 'product' => $this->product] + $this->flags() + ['expect' => $this->expect];
    }
}
