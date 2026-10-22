<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content\Entity;

use App\Core\Content\Validator\PageSlugAvailable;
use App\Core\Shared\Translation\AbstractTranslation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'content_page_translation')]
#[ORM\UniqueConstraint(name: 'uniq_content_page_translation_locale', columns: ['page_id', 'locale'])]
#[ORM\UniqueConstraint(name: 'uniq_content_page_translation_slug', columns: ['locale', 'slug'])]
#[UniqueEntity(fields: ['locale', 'slug'], message: 'content.page_translation.slug.unique', errorPath: 'slug', ignoreNull: true)]
#[PageSlugAvailable]
class PageTranslation extends AbstractTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Page $page;

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $title = '';

    /** Порожній заповнює SlugListener з title. */
    #[ORM\Column(length: 190, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $metaTitle = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $metaDescription = null;

    public function __construct(Page $page, string $locale)
    {
        parent::__construct($locale);
        $this->page = $page;
    }

    public function isBlank(): bool
    {
        return '' === $this->title && null === $this->slug && null === $this->body && null === $this->metaTitle && null === $this->metaDescription;
    }

    public function getPage(): Page
    {
        return $this->page;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = self::requireText($title, 'title');

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

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): self
    {
        $this->body = null === $body || '' === trim($body) ? null : $body;

        return $this;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function setMetaTitle(?string $metaTitle): self
    {
        $this->metaTitle = null === $metaTitle || '' === trim($metaTitle) ? null : self::requireText($metaTitle, 'metaTitle');

        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): self
    {
        $this->metaDescription = null === $metaDescription || '' === trim($metaDescription) ? null : self::requireText($metaDescription, 'metaDescription', 500);

        return $this;
    }
}
