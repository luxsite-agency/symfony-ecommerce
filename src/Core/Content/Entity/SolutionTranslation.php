<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content\Entity;

use App\Core\Content\TextList;
use App\Core\Shared\Translation\AbstractTranslation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'content_solution_translation')]
#[ORM\UniqueConstraint(name: 'uniq_content_solution_translation_locale', columns: ['solution_id', 'locale'])]
#[ORM\UniqueConstraint(name: 'uniq_content_solution_translation_slug', columns: ['locale', 'slug'])]
#[UniqueEntity(fields: ['locale', 'slug'], message: 'content.solution_translation.slug.unique', errorPath: 'slug', ignoreNull: true)]
class SolutionTranslation extends AbstractTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Solution $solution;

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $title = '';

    /** Порожній заповнює SlugListener з title. */
    #[ORM\Column(length: 190, nullable: true)]
    private ?string $slug = null;

    /** Вступ під H1 (текст, без HTML). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $intro = null;

    /** Блок «Задача» (текст, без HTML). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $task = null;

    /** Довільний HTML після варіантів (необов'язково). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $metaTitle = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $metaDescription = null;

    /** @var list<string>|null «Вантажі» (чипи) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $loads = null;

    /** @var list<string>|null «Галузі» (чипи) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $industries = null;

    /** @var list<array{q: string, a: string}>|null FAQ: блок під сторінкою і FAQPage у JSON-LD (як у Post) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $faq = null;

    public function __construct(Solution $solution, string $locale)
    {
        parent::__construct($locale);
        $this->solution = $solution;
    }

    public function isBlank(): bool
    {
        return '' === $this->title && null === $this->slug && null === $this->intro && null === $this->task && null === $this->body
            && null === $this->metaTitle && null === $this->metaDescription && null === $this->loads && null === $this->industries && null === $this->faq;
    }

    public function getSolution(): Solution
    {
        return $this->solution;
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): self
    {
        $this->slug = self::optionalSlug($slug);

        return $this;
    }

    public function getIntro(): ?string
    {
        return $this->intro;
    }

    public function setIntro(?string $intro): self
    {
        $this->intro = self::optionalText($intro);

        return $this;
    }

    public function getTask(): ?string
    {
        return $this->task;
    }

    public function setTask(?string $task): self
    {
        $this->task = self::optionalText($task);

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): self
    {
        $this->body = null === $body || '' === trim(strip_tags($body)) ? null : $body;

        return $this;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function setMetaTitle(?string $metaTitle): self
    {
        $this->metaTitle = null === $metaTitle || '' === trim($metaTitle) ? null : self::requireText($metaTitle, 'metaTitle');

        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): self
    {
        $this->metaDescription = null === $metaDescription || '' === trim($metaDescription) ? null : self::requireText($metaDescription, 'metaDescription', 500);

        return $this;
    }

    /** @return list<string> */
    public function getLoads(): array
    {
        return $this->loads ?? [];
    }

    /** @param iterable<string> $loads */
    public function setLoads(iterable $loads): self
    {
        $this->loads = TextList::clean($loads) ?: null;

        return $this;
    }

    /** @return list<string> */
    public function getIndustries(): array
    {
        return $this->industries ?? [];
    }

    /** @param iterable<string> $industries */
    public function setIndustries(iterable $industries): self
    {
        $this->industries = TextList::clean($industries) ?: null;

        return $this;
    }

    /** @return list<array{q: string, a: string}> MySQL JSON впорядковує ключі сам (a перед q) — порядок q, a відновлюється тут */
    public function getFaq(): array
    {
        return array_map(static fn (array $item): array => ['q' => $item['q'], 'a' => $item['a']], $this->faq ?? []);
    }

    /** @param list<array{q: string, a: string}> $faq */
    public function setFaq(array $faq): self
    {
        $this->faq = [] === $faq ? null : $faq;

        return $this;
    }

    /** Адмінка: рядок на пункт. */
    public function getLoadsText(): string
    {
        return TextList::toText($this->getLoads());
    }

    public function setLoadsText(?string $text): self
    {
        return $this->setLoads(TextList::fromText($text));
    }

    public function getIndustriesText(): string
    {
        return TextList::toText($this->getIndustries());
    }

    public function setIndustriesText(?string $text): self
    {
        return $this->setIndustries(TextList::fromText($text));
    }

    /** Адмінка: блоки через порожній рядок, перший рядок — питання. */
    public function getFaqText(): string
    {
        return TextList::faqToText($this->getFaq());
    }

    public function setFaqText(?string $text): self
    {
        return $this->setFaq(TextList::faqFromText($text));
    }

    private static function optionalText(?string $text): ?string
    {
        $text = trim((string) $text);

        return '' === $text ? null : $text;
    }
}
