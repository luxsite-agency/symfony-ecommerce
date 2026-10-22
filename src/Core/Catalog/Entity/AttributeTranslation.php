<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Shared\Translation\AbstractTranslation;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'catalog_attribute_translation')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_attribute_translation_locale', columns: ['attribute_id', 'locale'])]
class AttributeTranslation extends AbstractTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Attribute $attribute;

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $name = '';

    public function __construct(Attribute $attribute, string $locale)
    {
        parent::__construct($locale);
        $this->attribute = $attribute;
    }

    public function isBlank(): bool
    {
        return '' === $this->name;
    }

    public function getAttribute(): Attribute
    {
        return $this->attribute;
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
}
