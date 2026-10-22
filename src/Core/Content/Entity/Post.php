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
 * Запис блогу (новина, порада, огляд): рубрика, дата публікації, обкладинка з медіатеки, автор і теги рядками,
 * контент per-locale. Публічний, якщо publishedAt задано й уже настало та є переклад мовою запиту.
 */
#[ORM\Entity]
#[ORM\Table(name: 'content_post')]
#[ORM\Index(name: 'idx_content_post_published', columns: ['published_at'])]
#[ORM\UniqueConstraint(name: 'uniq_content_post_studio_group', columns: ['studio_group_id'])]
#[ORM\HasLifecycleCallbacks]
class Post implements TranslatableInterface
{
    /** @use TranslatableTrait<PostTranslation> */
    use TranslatableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?PostCategory $category = null;

    /** null — чернетка; майбутня дата — відкладена публікація. */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Media $cover = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $authorName = null;

    /** Теги через кому; окремої сутності у v1 нема. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $tags = null;

    /** Група перекладів статті у зовнішній контент-панелі (group_id панелі): приймач оновлює запис за нею; null — запис не з панелі. */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $studioGroupId = null;

    /** Обкладинка, яку поставила панель: якщо cover інший — його поставили вручну, і панель його не перетирає. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Media $studioCover = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $studioSyncedAt = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<string, PostTranslation> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: PostTranslation::class, mappedBy: 'post', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
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

    public function getCategory(): ?PostCategory
    {
        return $this->category;
    }

    public function setCategory(?PostCategory $category): void
    {
        $this->category = $category;
    }

    public function getPublishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?DateTimeImmutable $publishedAt): void
    {
        $this->publishedAt = $publishedAt;
    }

    public function isPublished(?DateTimeImmutable $now = null): bool
    {
        return null !== $this->publishedAt && $this->publishedAt <= ($now ?? new DateTimeImmutable());
    }

    public function getCover(): ?Media
    {
        return $this->cover;
    }

    public function setCover(?Media $cover): void
    {
        $this->cover = $cover;
    }

    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    public function setAuthorName(?string $authorName): void
    {
        $authorName = null === $authorName ? null : trim($authorName);
        $this->authorName = '' === $authorName ? null : $authorName;
    }

    public function getTags(): ?string
    {
        return $this->tags;
    }

    public function setTags(?string $tags): void
    {
        $tags = null === $tags ? null : trim($tags);
        $this->tags = '' === $tags ? null : $tags;
    }

    /** @return list<string> */
    public function getTagList(): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', (string) $this->tags)), static fn (string $t): bool => '' !== $t));
    }

    public function getStudioGroupId(): ?string
    {
        return $this->studioGroupId;
    }

    public function setStudioGroupId(?string $studioGroupId): void
    {
        $this->studioGroupId = $studioGroupId;
    }

    public function getStudioCover(): ?Media
    {
        return $this->studioCover;
    }

    /** Обкладинка від панелі: стає й поточною обкладинкою запису. */
    public function setStudioCover(Media $cover): void
    {
        $this->studioCover = $cover;
        $this->cover = $cover;
    }

    /** Обкладинку поставили вручну (в адмінці), а не панель: панель її не замінює. */
    public function hasManualCover(): bool
    {
        return null !== $this->cover && $this->cover !== $this->studioCover;
    }

    /** Підпис панелі під обкладинкою показується лише тоді, коли на сторінці саме її фото. */
    public function isStudioCoverShown(): bool
    {
        return null !== $this->cover && $this->cover === $this->studioCover;
    }

    public function getStudioSyncedAt(): ?DateTimeImmutable
    {
        return $this->studioSyncedAt;
    }

    public function markStudioSynced(): void
    {
        $this->studioSyncedAt = new DateTimeImmutable();
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
        return $this->displayText(static fn (PostTranslation $t): string => $t->getTitle()) ?? \sprintf('#%d', $this->id ?? 0);
    }

    protected function createTranslation(string $locale): AbstractTranslation
    {
        return new PostTranslation($this, $locale);
    }
}
