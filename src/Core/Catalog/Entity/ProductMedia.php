<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Catalog\Exception\CatalogInvariantViolation;
use Doctrine\ORM\Mapping as ORM;

/** Файл у галереї товару з порядком; з variant — фото конкретного варіанта. Створюється через Product::addMedia(). */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_product_media')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_product_media', columns: ['product_id', 'media_id'])]
class ProductMedia
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'media')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Media $media;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ProductVariant $variant = null;

    #[ORM\Column]
    private int $position = 0;

    /** @internal використовуйте Product::addMedia() */
    public function __construct(Product $product, Media $media)
    {
        $this->product = $product;
        $this->media = $media;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getMedia(): Media
    {
        return $this->media;
    }

    public function getVariant(): ?ProductVariant
    {
        return $this->variant;
    }

    public function setVariant(?ProductVariant $variant): void
    {
        if (null !== $variant && $variant->getProduct() !== $this->product) {
            throw new CatalogInvariantViolation('Фото можна прив\'язати лише до варіанта цього ж товару', 'error.product_media.foreign_variant');
        }
        $this->variant = $variant;
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
