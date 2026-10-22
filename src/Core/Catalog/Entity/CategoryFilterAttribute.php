<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Catalog\Exception\CatalogInvariantViolation;
use Doctrine\ORM\Mapping as ORM;

/**
 * Яка характеристика показується у фасетному блоці категорії і в якому порядку (ADR Rev 9, донор params_for_filter).
 * Без такого списку фасети категорії — усі filterable-характеристики (fallback CategoryFacetResolver), що для
 * каталогу з тисячами значень неприйнятно. Створюється лише через Category::addFilterAttribute().
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_category_filter_attribute')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_category_filter_attribute', columns: ['category_id', 'attribute_id'])]
class CategoryFilterAttribute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'filterAttributes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Category $category;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Attribute $attribute;

    #[ORM\Column]
    private int $position = 0;

    /** @internal використовуйте Category::addFilterAttribute() */
    public function __construct(Category $category, Attribute $attribute, int $position = 0)
    {
        if (!$attribute->isFilterable()) {
            throw new CatalogInvariantViolation(\sprintf('Характеристика "%s" не позначена як filterable — в індексі фільтрів її нема', $attribute->getCode()), 'error.category.filter_not_filterable', ['attribute' => $attribute->getCode()]);
        }
        $this->category = $category;
        $this->attribute = $attribute;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function getAttribute(): Attribute
    {
        return $this->attribute;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
