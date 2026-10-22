<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Shared\Text\Slug;
use App\Core\Shared\Translation\TranslatableInvalidArgumentException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/** Бренд: власна назва, не перекладається. */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_brand')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_brand_slug', columns: ['slug'])]
#[UniqueEntity(fields: ['slug'], message: 'catalog.brand.slug.unique')]
class Brand
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'catalog.brand.name.not_blank')]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\Column(length: 190)]
    #[Assert\NotBlank(message: 'catalog.brand.slug.not_blank')]
    private string $slug;

    #[ORM\Column]
    private bool $active = true;

    public function __construct(string $name, string $slug)
    {
        $this->rename($name);
        $this->changeSlug($slug);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 255) {
            throw new TranslatableInvalidArgumentException('Назва бренду має бути непорожньою і не довшою за 255 символів', 'error.brand.name_length');
        }
        $this->name = $name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function changeSlug(string $slug): void
    {
        $this->slug = Slug::assertValid($slug);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }
}
