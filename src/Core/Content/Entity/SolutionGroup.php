<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content\Entity;

use App\Core\Shared\Translation\AbstractTranslation;
use App\Core\Shared\Translation\TranslatableInterface;
use App\Core\Shared\Translation\TranslatableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Група рішень на сторінці списку («Системи зберігання», «Процеси складу»): лише назва мовами й порядок. */
#[ORM\Entity]
#[ORM\Table(name: 'content_solution_group')]
class SolutionGroup implements TranslatableInterface
{
    /** @use TranslatableTrait<SolutionGroupTranslation> */
    use TranslatableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<string, SolutionGroupTranslation> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: SolutionGroupTranslation::class, mappedBy: 'group', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function __toString(): string
    {
        return $this->displayText(static fn (SolutionGroupTranslation $t): string => $t->getTitle()) ?? \sprintf('#%d', $this->id ?? 0);
    }

    protected function createTranslation(string $locale): AbstractTranslation
    {
        return new SolutionGroupTranslation($this, $locale);
    }
}
