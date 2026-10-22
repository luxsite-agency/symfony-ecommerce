<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Shared\Translation\AbstractTranslation;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'catalog_attribute_value_translation')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_attribute_value_translation_locale', columns: ['attribute_value_id', 'locale'])]
class AttributeValueTranslation extends AbstractTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AttributeValue $attributeValue;

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $value = '';

    public function __construct(AttributeValue $attributeValue, string $locale)
    {
        parent::__construct($locale);
        $this->attributeValue = $attributeValue;
    }

    public function isBlank(): bool
    {
        return '' === $this->value;
    }

    public function getAttributeValue(): AttributeValue
    {
        return $this->attributeValue;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = self::requireText($value, 'value');

        return $this;
    }
}
