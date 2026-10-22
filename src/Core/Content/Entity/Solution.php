<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content\Entity;

use App\Core\Catalog\Entity\Media;
use App\Core\Shared\Translation\AbstractTranslation;
use App\Core\Shared\Translation\TranslatableInterface;
use App\Core\Shared\Translation\TranslatableTrait;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Рішення (B2B): задача клієнта → кілька товарів каталогу як варіанти (SolutionOption) → розрахунок. Контент і слаг per-locale,
 * як у Page; публічно — лише published і з перекладом мовою запиту (інакше 404). Ціни варіантів не зберігаються: їх показує
 * товар (price_mode). docs/solutions.md.
 */
#[ORM\Entity]
#[ORM\Table(name: 'content_solution')]
#[ORM\Index(name: 'idx_content_solution_list', columns: ['published', 'position'])]
#[ORM\HasLifecycleCallbacks]
class Solution implements TranslatableInterface
{
    /** @use TranslatableTrait<SolutionTranslation> */
    use TranslatableTrait;

    /** Скільки «інших рішень» показує сторінка. */
    public const int RELATED_LIMIT = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $published = false;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?SolutionGroup $group = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Media $cover = null;

    /** @var Collection<int, Solution> */
    #[ORM\ManyToMany(targetEntity: self::class)]
    #[ORM\JoinTable(name: 'content_solution_related')]
    #[ORM\JoinColumn(name: 'solution_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'related_id', onDelete: 'CASCADE')]
    private Collection $relatedSolutions;

    /** @var Collection<int, SolutionOption> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: SolutionOption::class, mappedBy: 'solution', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $options;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<string, SolutionTranslation> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: SolutionTranslation::class, mappedBy: 'solution', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->options = new ArrayCollection();
        $this->relatedSolutions = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): void
    {
        $this->published = $published;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getGroup(): ?SolutionGroup
    {
        return $this->group;
    }

    public function setGroup(?SolutionGroup $group): void
    {
        $this->group = $group;
    }

    public function getCover(): ?Media
    {
        return $this->cover;
    }

    public function setCover(?Media $cover): void
    {
        $this->cover = $cover;
    }

    /** @return Collection<int, Solution> */
    public function getRelatedSolutions(): Collection
    {
        return $this->relatedSolutions;
    }

    public function addRelatedSolution(self $solution): void
    {
        if ($solution !== $this && !$this->relatedSolutions->contains($solution)) {
            $this->relatedSolutions->add($solution);
        }
    }

    public function removeRelatedSolution(self $solution): void
    {
        $this->relatedSolutions->removeElement($solution);
    }

    /** @return Collection<int, SolutionOption> */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    /**
     * Варіанти в порядку показу: position, далі порядок додавання (id; нові без id — в кінці в порядку колекції).
     * OrderBy діє лише при завантаженні з БД — тут той самий порядок і для щойно змінених у пам'яті.
     *
     * @return list<SolutionOption>
     */
    public function getSortedOptions(): array
    {
        $options = $this->options->toArray();
        $index = array_flip(array_keys($options));
        uksort($options, static function (int|string $a, int|string $b) use ($options, $index): int {
            return [$options[$a]->getPosition(), $options[$a]->getId() ?? \PHP_INT_MAX, $index[$a]]
                <=> [$options[$b]->getPosition(), $options[$b]->getId() ?? \PHP_INT_MAX, $index[$b]];
        });

        return array_values($options);
    }

    public function addOption(SolutionOption $option): void
    {
        if (!$this->options->contains($option)) {
            $option->setSolution($this);
            $this->options->add($option);
        }
    }

    public function removeOption(SolutionOption $option): void
    {
        $this->options->removeElement($option);
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->displayText(static fn (SolutionTranslation $t): string => $t->getTitle()) ?? \sprintf('#%d', $this->id ?? 0);
    }

    protected function createTranslation(string $locale): AbstractTranslation
    {
        return new SolutionTranslation($this, $locale);
    }
}
