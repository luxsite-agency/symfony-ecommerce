<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Shared\Translation\AbstractTranslation;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'catalog_option_value_translation')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_option_value_translation_locale', columns: ['option_value_id', 'locale'])]
class OptionValueTranslation extends AbstractTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OptionValue $optionValue;

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $label = '';

    public function __construct(OptionValue $optionValue, string $locale)
    {
        parent::__construct($locale);
        $this->optionValue = $optionValue;
    }

    public function isBlank(): bool
    {
        return '' === $this->label;
    }

    public function getOptionValue(): OptionValue
    {
        return $this->optionValue;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = self::requireText($label, 'label');

        return $this;
    }
}
