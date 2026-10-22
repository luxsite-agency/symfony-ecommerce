<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

use App\Core\Catalog\Enum\AttributeType;

/** Характеристика фасетного блоку категорії (CategoryFacetResolver): id/code/тип — усе, що треба запиту й шаблону. */
final readonly class FacetDefinition
{
    public function __construct(
        public int $attributeId,
        public string $code,
        public AttributeType $type,
        public bool $variantAxis,
        public ?string $unit = null,
    ) {
    }

    public function hasOptions(): bool
    {
        return AttributeType::Select === $this->type || AttributeType::MultiSelect === $this->type;
    }

    public function isNumber(): bool
    {
        return AttributeType::Number === $this->type;
    }

    public function isBoolean(): bool
    {
        return AttributeType::Boolean === $this->type;
    }
}
