<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Catalog\Validator\AvailableSiteSlug;
use App\Core\Shared\Translation\AbstractTranslation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/** Текст картки однією мовою. FULLTEXT на name — пошук у v1 без sidecar (ADR Settled). */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_product_translation')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_product_translation_locale', columns: ['product_id', 'locale'])]
#[ORM\UniqueConstraint(name: 'uniq_catalog_product_translation_slug', columns: ['locale', 'slug'])]
#[UniqueEntity(fields: ['locale', 'slug'], message: 'catalog.product_translation.slug.unique', errorPath: 'slug', ignoreNull: true)]
#[AvailableSiteSlug]
#[ORM\Index(name: 'ft_catalog_product_translation_name', columns: ['name'], flags: ['fulltext'])]
class ProductTranslation extends AbstractTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $shortDescription = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitle = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $metaDescription = null;

    public function __construct(Product $product, string $locale)
    {
        parent::__construct($locale);
        $this->product = $product;
    }

    /** Порожній переклад (нічого не заповнено) форма не зберігає. */
    public function isBlank(): bool
    {
        return '' === $this->name && null === $this->slug && null === $this->shortDescription && null === $this->description && null === $this->metaTitle && null === $this->metaDescription;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = self::requireText($name, 'name');

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): self
    {
        $this->slug = self::optionalSlug($slug);

        return $this;
    }

    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function setShortDescription(?string $shortDescription): self
    {
        $this->shortDescription = $shortDescription;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function setMetaTitle(?string $metaTitle): self
    {
        $this->metaTitle = $metaTitle;

        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): self
    {
        $this->metaDescription = $metaDescription;

        return $this;
    }
}
