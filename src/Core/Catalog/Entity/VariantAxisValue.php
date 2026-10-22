<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Catalog\Exception\CatalogInvariantViolation;
use Doctrine\ORM\Mapping as ORM;

/**
 * Значення однієї осі варіанта (колір = білий, розмір = 42 — ADR Rev 2). Явна сутність замість ManyToMany,
 * щоб БД тримала «одне значення на вісь»: UNIQUE(variant_id, attribute_id) (ADR Rev 9, п. 2).
 * Створюється лише через ProductVariant::setAxisValue().
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_product_variant_axis_value')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_product_variant_axis', columns: ['variant_id', 'attribute_id'])]
#[ORM\Index(name: 'idx_catalog_product_variant_axis_value', columns: ['attribute_value_id'])]
class VariantAxisValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'axisValues')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProductVariant $variant;

    /** Вісь; дублює $value->getAttribute(), бо саме по цій колонці працює unique. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Attribute $attribute;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'attribute_value_id', nullable: false, onDelete: 'CASCADE')]
    private AttributeValue $value;

    /** @internal використовуйте ProductVariant::setAxisValue() */
    public function __construct(ProductVariant $variant, AttributeValue $value)
    {
        $this->variant = $variant;
        $this->attribute = $value->getAttribute();
        $this->value = $value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getAttribute(): Attribute
    {
        return $this->attribute;
    }

    public function getValue(): AttributeValue
    {
        return $this->value;
    }

    /** @internal заміна значення тієї ж осі на місці (без DELETE+INSERT, які в одному flush зачепили б unique) */
    public function changeValue(AttributeValue $value): void
    {
        if ($value->getAttribute() !== $this->attribute) {
            throw new CatalogInvariantViolation(\sprintf('Значення "%s" не належить осі "%s"', $value->getCode(), $this->attribute->getCode()), 'error.variant.axis_value_foreign', ['value' => $value->getCode(), 'attribute' => $this->attribute->getCode()]);
        }
        $this->value = $value;
    }
}
