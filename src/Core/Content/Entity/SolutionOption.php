<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content\Entity;

use App\Core\Catalog\Entity\Product;
use App\Core\Shared\Translation\AbstractTranslation;
use App\Core\Shared\Translation\TranslatableInterface;
use App\Core\Shared\Translation\TranslatableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Варіант рішення — товар каталогу з перевагами (pros) і абзацом (note) мовами. Ціна й «Запитати ціну» — з товару (price_mode),
 * тут не дублюються. Товар видалено → варіант зникає (CASCADE). Індекс product_id — для блоку «Рішення з цим продуктом».
 */
#[ORM\Entity]
#[ORM\Table(name: 'content_solution_option')]
#[ORM\UniqueConstraint(name: 'uniq_content_solution_option_product', columns: ['solution_id', 'product_id'])]
#[ORM\Index(name: 'idx_content_solution_option_product', columns: ['product_id'])]
#[UniqueEntity(fields: ['solution', 'product'], message: 'content.solution_option.product.unique', errorPath: 'product')]
class SolutionOption implements TranslatableInterface
{
    /** @use TranslatableTrait<SolutionOptionTranslation> */
    use TranslatableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'options')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Solution $solution = null; // @phpstan-ignore doctrine.associationType (новий рядок форми адмінки — до addOption без рішення)

    #[Assert\NotNull]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Product $product = null; // @phpstan-ignore doctrine.associationType (новий рядок форми — до вибору товару; NotNull валідує)

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<string, SolutionOptionTranslation> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: SolutionOptionTranslation::class, mappedBy: 'option', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    public function __construct(?Solution $solution = null, ?Product $product = null)
    {
        $this->translations = new ArrayCollection();
        $this->solution = $solution;
        $this->product = $product;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSolution(): ?Solution
    {
        return $this->solution;
    }

    public function setSolution(?Solution $solution): void
    {
        $this->solution = $solution;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): void
    {
        $this->product = $product;
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
        return (string) $this->product;
    }

    protected function createTranslation(string $locale): AbstractTranslation
    {
        return new SolutionOptionTranslation($this, $locale);
    }
}
