<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content\Entity;

use App\Core\Content\TextList;
use App\Core\Shared\Translation\AbstractTranslation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'content_solution_option_translation')]
#[ORM\UniqueConstraint(name: 'uniq_content_solution_option_translation_locale', columns: ['option_id', 'locale'])]
class SolutionOptionTranslation extends AbstractTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SolutionOption $option;

    /** @var list<string>|null переваги варіанта (галочки) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $pros = null;

    /** Абзац під перевагами (текст, без HTML). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    public function __construct(SolutionOption $option, string $locale)
    {
        parent::__construct($locale);
        $this->option = $option;
    }

    public function isBlank(): bool
    {
        return null === $this->pros && null === $this->note;
    }

    public function getOption(): SolutionOption
    {
        return $this->option;
    }

    /** @return list<string> */
    public function getPros(): array
    {
        return $this->pros ?? [];
    }

    /** @param iterable<string> $pros */
    public function setPros(iterable $pros): self
    {
        $this->pros = TextList::clean($pros) ?: null;

        return $this;
    }

    /** Адмінка: рядок на перевагу. */
    public function getProsText(): string
    {
        return TextList::toText($this->getPros());
    }

    public function setProsText(?string $text): self
    {
        return $this->setPros(TextList::fromText($text));
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $note = trim((string) $note);
        $this->note = '' === $note ? null : $note;

        return $this;
    }
}
