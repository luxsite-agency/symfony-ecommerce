<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content\Entity;

use App\Core\Shared\Translation\AbstractTranslation;
use App\Core\Shared\Translation\TranslatableInterface;
use App\Core\Shared\Translation\TranslatableTrait;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Статична сторінка сайту (про нас, доставка, контакти, оферта): контент per-locale, слаг per-locale.
 * Показується публічно лише якщо published і є переклад мовою запиту (інакше 404).
 */
#[ORM\Entity]
#[ORM\Table(name: 'content_page')]
#[ORM\Index(name: 'idx_content_page_nav', columns: ['published', 'position'])]
#[ORM\HasLifecycleCallbacks]
class Page implements TranslatableInterface
{
    /** @use TranslatableTrait<PageTranslation> */
    use TranslatableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $published = false;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $showInHeader = false;

    #[ORM\Column]
    private bool $showInFooter = false;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<string, PageTranslation> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: PageTranslation::class, mappedBy: 'page', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
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

    public function isShowInHeader(): bool
    {
        return $this->showInHeader;
    }

    public function setShowInHeader(bool $show): void
    {
        $this->showInHeader = $show;
    }

    public function isShowInFooter(): bool
    {
        return $this->showInFooter;
    }

    public function setShowInFooter(bool $show): void
    {
        $this->showInFooter = $show;
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
        return $this->displayText(static fn (PageTranslation $t): string => $t->getTitle()) ?? \sprintf('#%d', $this->id ?? 0);
    }

    protected function createTranslation(string $locale): AbstractTranslation
    {
        return new PageTranslation($this, $locale);
    }
}
