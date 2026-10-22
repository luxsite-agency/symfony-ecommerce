<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Shared\Translation\AbstractTranslation;
use App\Core\Shared\Translation\TranslatableInterface;
use App\Core\Shared\Translation\TranslatableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Категорія: дерево через parent_id (adjacency list). Closure-таблиці нема (ADR D3): сотні категорій — дерево в пам'яті.
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_category')]
#[ORM\Index(name: 'idx_catalog_category_parent_position', columns: ['parent_id', 'position'])]
class Category implements TranslatableInterface
{
    /** @use TranslatableTrait<CategoryTranslation> */
    use TranslatableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Category $parent = null;

    /** @var Collection<int, Category> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $children;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $active = true;

    /** Зображення плитки на головній (з медіатеки); null — фото першого товару категорії (HomeShowcase). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Media $image = null;

    /** @var Collection<string, CategoryTranslation> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: CategoryTranslation::class, mappedBy: 'category', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    /**
     * Характеристики фасетного блоку цієї категорії в порядку показу (ADR Rev 9). Порожньо — успадковується від
     * найближчого предка зі списком (CategoryFacetResolver).
     *
     * @var Collection<int, CategoryFilterAttribute>
     */
    #[ORM\OneToMany(targetEntity: CategoryFilterAttribute::class, mappedBy: 'category', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $filterAttributes;

    public function __construct(?self $parent = null)
    {
        $this->children = new ArrayCollection();
        $this->translations = new ArrayCollection();
        $this->filterAttributes = new ArrayCollection();
        $this->setParent($parent);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** Назва першим наявним перекладом; для дерева в адмінці — див. getPathLabel(). */
    public function __toString(): string
    {
        return $this->displayText(static fn (CategoryTranslation $t): string => $t->getName()) ?? \sprintf('#%d', $this->id ?? 0);
    }

    /** «Двері / Міжкімнатні»: шлях від кореня для списків і селектів. */
    public function getPathLabel(string $separator = ' / '): string
    {
        return implode($separator, array_map(strval(...), [...$this->getAncestors(), $this]));
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    /** Перенесення в іншу гілку; категорію не можна зробити нащадком самої себе. */
    public function setParent(?self $parent): void
    {
        if ($parent === $this->parent) {
            return;
        }
        for ($ancestor = $parent; null !== $ancestor; $ancestor = $ancestor->parent) {
            if ($ancestor === $this) {
                throw new CatalogInvariantViolation('Категорія не може бути власним предком', 'error.category.own_ancestor');
            }
        }

        $this->parent?->children->removeElement($this);
        $this->parent = $parent;
        $parent?->children->add($this);
    }

    /** @return Collection<int, Category> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    /**
     * Предки від кореня до безпосереднього батька.
     *
     * @return list<Category>
     */
    public function getAncestors(): array
    {
        $ancestors = [];
        for ($ancestor = $this->parent; null !== $ancestor; $ancestor = $ancestor->parent) {
            array_unshift($ancestors, $ancestor);
        }

        return $ancestors;
    }

    public function getDepth(): int
    {
        return \count($this->getAncestors());
    }

    public function isDescendantOf(self $other): bool
    {
        return \in_array($other, $this->getAncestors(), true);
    }

    // --- фасети категорії (ADR Rev 9) ---

    /** Додає характеристику у фасетний блок (у кінець); повторне додавання повертає наявний запис. */
    public function addFilterAttribute(Attribute $attribute): CategoryFilterAttribute
    {
        $existing = $this->findFilterAttribute($attribute);
        if (null !== $existing) {
            return $existing;
        }
        $link = new CategoryFilterAttribute($this, $attribute, $this->filterAttributes->count());
        $this->filterAttributes->add($link);

        return $link;
    }

    public function removeFilterAttribute(Attribute $attribute): void
    {
        $existing = $this->findFilterAttribute($attribute);
        if (null !== $existing) {
            $this->filterAttributes->removeElement($existing);
        }
    }

    public function findFilterAttribute(Attribute $attribute): ?CategoryFilterAttribute
    {
        return $this->filterAttributes->findFirst(static fn (int $_, CategoryFilterAttribute $f): bool => $f->getAttribute() === $attribute);
    }

    /** @return Collection<int, CategoryFilterAttribute> */
    public function getFilterAttributes(): Collection
    {
        return $this->filterAttributes;
    }

    /** @return list<Attribute> у порядку показу */
    public function getFilterAttributeList(): array
    {
        return array_values($this->filterAttributes->map(static fn (CategoryFilterAttribute $f): Attribute => $f->getAttribute())->toArray());
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getImage(): ?Media
    {
        return $this->image;
    }

    public function setImage(?Media $image): void
    {
        $this->image = $image;
    }

    protected function createTranslation(string $locale): AbstractTranslation
    {
        return new CategoryTranslation($this, $locale);
    }
}
