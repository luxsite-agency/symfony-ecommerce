<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Shared\Translation\AbstractTranslation;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'catalog_option_group_translation')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_option_group_translation_locale', columns: ['group_id', 'locale'])]
class OptionGroupTranslation extends AbstractTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OptionGroup $group;

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $name = '';

    public function __construct(OptionGroup $group, string $locale)
    {
        parent::__construct($locale);
        $this->group = $group;
    }

    public function isBlank(): bool
    {
        return '' === $this->name;
    }

    public function getOptionGroup(): OptionGroup
    {
        return $this->group;
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
