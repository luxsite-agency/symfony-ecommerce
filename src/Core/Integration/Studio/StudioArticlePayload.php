<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Studio;

use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Тіло PUT /api/studio/articles/{externalId} — те, що шле зовнішня контент-панель. Одна стаття
 * однією мовою; group_id спільний для перекладів. Поля, яких приймач не використовує (stats, images,
 * updated_content_at), ігноруються. FAQ, джерела й «Читайте також» — масиви рядків {q, a} / {title, url},
 * їх нормалізує StudioArticlePublisher.
 */
final readonly class StudioArticlePayload
{
    /**
     * @param array<mixed> $faq     list<{q, a}>
     * @param array<mixed> $sources list<{title, url}>
     * @param array<mixed> $related list<{title, url}>
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Language required.')]
        public string $lang = '',
        #[SerializedName('group_id')]
        #[Assert\NotBlank(message: 'Translation group UUID required.')]
        #[Assert\Regex('/^[0-9a-fA-F-]{36}$/', message: 'Translation group UUID required.')]
        public string $groupId = '',
        #[Assert\NotBlank(message: 'Empty title.')]
        #[Assert\Length(max: 255)]
        public string $title = '',
        #[Assert\NotBlank(message: 'Empty slug.')]
        #[Assert\Length(max: 190)]
        #[Assert\Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'Slug: latin lowercase letters, digits and single hyphens only.')]
        public string $slug = '',
        #[SerializedName('body_html')]
        #[Assert\NotBlank(message: 'Empty body.')]
        public string $bodyHtml = '',
        #[Assert\Choice(choices: ['article', 'news'])]
        public string $kind = 'article',
        public ?string $lead = null,
        #[SerializedName('meta_title')]
        public ?string $metaTitle = null,
        #[SerializedName('meta_desc')]
        public ?string $metaDesc = null,
        #[SerializedName('cover_url')]
        public ?string $coverUrl = null,
        #[SerializedName('cover_alt')]
        public ?string $coverAlt = null,
        #[SerializedName('cover_caption')]
        public ?string $coverCaption = null,
        #[SerializedName('cover_credit')]
        public ?string $coverCredit = null,
        public array $faq = [],
        public array $sources = [],
        #[SerializedName('sources_format')]
        #[Assert\Choice(choices: ['link', 'text', 'off'])]
        public string $sourcesFormat = 'link',
        public array $related = [],
        #[SerializedName('category_slug')]
        #[Assert\Regex('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/')]
        #[Assert\Length(max: 64)]
        public ?string $categorySlug = null,
        #[Assert\Length(max: 120)]
        public ?string $author = null,
        #[SerializedName('published_at')]
        public ?DateTimeImmutable $publishedAt = null,
    ) {
    }
}
