<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content\Entity;

use App\Core\Shared\Text\MachineCode;
use App\Core\Shared\Translation\AbstractTranslation;
use App\Core\Shared\Translation\TranslatableInterface;
use App\Core\Shared\Translation\TranslatableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/** Рубрика блогу (новини, поради, огляди): код для коду, назва й слаг per-locale. */
#[ORM\Entity]
#[ORM\Table(name: 'content_post_category')]
#[ORM\UniqueConstraint(name: 'uniq_content_post_category_code', columns: ['code'])]
#[UniqueEntity(fields: ['code'], message: 'content.post_category.code.unique')]
class PostCategory implements TranslatableInterface
{
    /** @use TranslatableTrait<PostCategoryTranslation> */
    use TranslatableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank(message: 'content.post_category.code.not_blank')]
    private string $code;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<string, PostCategoryTranslation> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: PostCategoryTranslation::class, mappedBy: 'category', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    public function __construct(string $code)
    {
        $this->code = MachineCode::assertValid($code);
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function changeCode(string $code): void
    {
        $this->code = MachineCode::assertValid($code);
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
        return $this->displayText(static fn (PostCategoryTranslation $t): string => $t->getName()) ?? $this->code;
    }

    protected function createTranslation(string $locale): AbstractTranslation
    {
        return new PostCategoryTranslation($this, $locale);
    }
}
