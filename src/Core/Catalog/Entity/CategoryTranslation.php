<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Catalog\Validator\AvailableSiteSlug;
use App\Core\Shared\Translation\AbstractTranslation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'catalog_category_translation')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_category_translation_locale', columns: ['category_id', 'locale'])]
#[ORM\UniqueConstraint(name: 'uniq_catalog_category_translation_path', columns: ['locale', 'path'])]
#[ORM\Index(name: 'idx_catalog_category_translation_slug', columns: ['locale', 'slug'])]
#[AvailableSiteSlug]
class CategoryTranslation extends AbstractTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Category $category;

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $slug = null;

    /**
     * Повний шлях за деревом цією мовою («odyah/futbolky»): адреса /odyah/futbolky/. Рахує CategoryPaths після flush
     * (слаг унікальний серед сестер, шлях — у межах мови); null — нема слага тут чи в когось із предків.
     */
    #[ORM\Column(length: 700, nullable: true)]
    private ?string $path = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitle = null;

    /** Окремий H1 сторінки категорії (SEO п. 10); порожній — name (меню, крихти й плитки завжди name). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $h1 = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $metaDescription = null;

    public function __construct(Category $category, string $locale)
    {
        parent::__construct($locale);
        $this->category = $category;
    }

    public function isBlank(): bool
    {
        return '' === $this->name && null === $this->slug && null === $this->description && null === $this->metaTitle && null === $this->metaDescription && null === $this->h1;
    }

    public function getCategory(): Category
    {
        return $this->category;
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

    public function getPath(): ?string
    {
        return $this->path;
    }

    /** @internal лише CategoryPaths::syncManaged — шлях рахується SQL-ом за деревом, не задається вручну */
    public function syncPath(?string $path): void
    {
        $this->path = $path;
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

    public function getH1(): ?string
    {
        return $this->h1;
    }

    public function setH1(?string $h1): self
    {
        $h1 = null === $h1 ? null : trim($h1);
        $this->h1 = null === $h1 || '' === $h1 ? null : mb_substr($h1, 0, 255);

        return $this;
    }

    /** Заголовок сторінки категорії: h1, якщо задано, інакше name. */
    public function getHeading(): string
    {
        return $this->h1 ?? $this->name;
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
