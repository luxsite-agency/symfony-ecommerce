<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content\Entity;

use App\Core\Shared\Translation\AbstractTranslation;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'content_post_category_translation')]
#[ORM\UniqueConstraint(name: 'uniq_content_post_category_translation_locale', columns: ['category_id', 'locale'])]
#[ORM\UniqueConstraint(name: 'uniq_content_post_category_translation_slug', columns: ['locale', 'slug'])]
#[UniqueEntity(fields: ['locale', 'slug'], message: 'content.post_category_translation.slug.unique', errorPath: 'slug', ignoreNull: true)]
class PostCategoryTranslation extends AbstractTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PostCategory $category;

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $slug = null;

    public function __construct(PostCategory $category, string $locale)
    {
        parent::__construct($locale);
        $this->category = $category;
    }

    public function isBlank(): bool
    {
        return '' === $this->name && null === $this->slug;
    }

    public function getCategory(): PostCategory
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

    /** Для SlugListener: джерело слага. */
    public function getTitle(): string
    {
        return $this->name;
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
}
