<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content\Entity;

use App\Core\Shared\Translation\AbstractTranslation;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'content_solution_group_translation')]
#[ORM\UniqueConstraint(name: 'uniq_content_solution_group_translation_locale', columns: ['group_id', 'locale'])]
class SolutionGroupTranslation extends AbstractTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SolutionGroup $group;

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $title = '';

    public function __construct(SolutionGroup $group, string $locale)
    {
        parent::__construct($locale);
        $this->group = $group;
    }

    public function isBlank(): bool
    {
        return '' === $this->title;
    }

    public function getGroup(): SolutionGroup
    {
        return $this->group;
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
}
