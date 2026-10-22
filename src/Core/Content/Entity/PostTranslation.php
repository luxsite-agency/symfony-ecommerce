<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content\Entity;

use App\Core\Shared\Translation\AbstractTranslation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'content_post_translation')]
#[ORM\UniqueConstraint(name: 'uniq_content_post_translation_locale', columns: ['post_id', 'locale'])]
#[ORM\UniqueConstraint(name: 'uniq_content_post_translation_slug', columns: ['locale', 'slug'])]
#[UniqueEntity(fields: ['locale', 'slug'], message: 'content.post_translation.slug.unique', errorPath: 'slug', ignoreNull: true)]
class PostTranslation extends AbstractTranslation
{
    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Post $post;

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $title = '';

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $excerpt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $metaTitle = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $metaDescription = null;

    /** @var list<array{q: string, a: string}>|null FAQ статті (зовнішня контент-панель): блок під текстом і FAQPage у JSON-LD */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $faq = null;

    /** @var list<array{title: string, url: string}>|null «Читайте також» (зовнішня контент-панель): внутрішні посилання, шлях без хоста */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $related = null;

    /** Підпис під обкладинкою від панелі: що на фото й атрибуція (автор, джерело, ліцензія — обов'язково для CC BY). */
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $coverCaption = null;

    public function __construct(Post $post, string $locale)
    {
        parent::__construct($locale);
        $this->post = $post;
    }

    /**
     * Повний переклад — є заголовок і текст: лише для таких існує URL мовою, hreflang і запис у sitemap (SEO п. 2).
     * Вкладка мови зі самим слагом/метою (автослаг, порожній запис, заголовок без тексту) перекладом не вважається.
     */
    public function isComplete(): bool
    {
        return '' !== trim($this->title) && '' !== trim(html_entity_decode(strip_tags((string) $this->body), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{A0}");
    }

    public function isBlank(): bool
    {
        return '' === $this->title && null === $this->slug && null === $this->excerpt && null === $this->body && null === $this->metaTitle && null === $this->metaDescription
            && null === $this->faq && null === $this->related && null === $this->coverCaption;
    }

    public function getPost(): Post
    {
        return $this->post;
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

    public function getExcerpt(): ?string
    {
        return $this->excerpt;
    }

    public function setExcerpt(?string $excerpt): self
    {
        $this->excerpt = null === $excerpt || '' === trim($excerpt) ? null : trim($excerpt);

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): self
    {
        $this->body = null === $body || '' === trim($body) ? null : $body;

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

    /** @return list<array{q: string, a: string}> */
    public function getFaq(): array
    {
        return $this->faq ?? [];
    }

    /** @param list<array{q: string, a: string}> $faq */
    public function setFaq(array $faq): self
    {
        $this->faq = [] === $faq ? null : $faq;

        return $this;
    }

    /** @return list<array{title: string, url: string}> */
    public function getRelated(): array
    {
        return $this->related ?? [];
    }

    /** @param list<array{title: string, url: string}> $related */
    public function setRelated(array $related): self
    {
        $this->related = [] === $related ? null : $related;

        return $this;
    }

    public function getCoverCaption(): ?string
    {
        return $this->coverCaption;
    }

    public function setCoverCaption(?string $coverCaption): self
    {
        $this->coverCaption = null === $coverCaption || '' === trim($coverCaption) ? null : self::requireText($coverCaption, 'coverCaption', 500);

        return $this;
    }
}
