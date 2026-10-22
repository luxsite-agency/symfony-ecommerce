<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Seo;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductReview;
use App\Core\Catalog\Repository\ProductReviewRepository;
use App\Core\Catalog\Review\AuthorName;
use App\Core\Catalog\Review\ProductRatings;
use App\Core\Catalog\Review\ReviewSettings;

/**
 * AggregateRating і Review для schema.org Product — лише зі схвалених справжніх відгуків (демо-відгуки публічного демо
 * не йдуть: вигадані оцінки в розмітці — порушення правил Google). Без таких відгуків — null, і макрос product нічого не
 * додає. Review — останні 10, автор «Ім'я П.», текст як є.
 */
final class ReviewSchema
{
    private const int REVIEWS = 10;

    public function __construct(
        private readonly ProductRatings $ratings,
        private readonly ProductReviewRepository $repository,
        private readonly ReviewSettings $settings,
    ) {
    }

    /** @return array{aggregateRating: array<string, mixed>, review: list<array<string, mixed>>}|null */
    public function rating(Product $product): ?array
    {
        if (!$this->settings->enabled) {
            return null;
        }
        $summary = $this->ratings->forProduct((int) $product->getId());
        if (null === $summary || 0 === $summary->realCount) {
            return null;
        }

        return [
            'aggregateRating' => [
                '@type' => 'AggregateRating',
                'ratingValue' => (string) $summary->realAverage(),
                'reviewCount' => $summary->realCount,
                'bestRating' => (string) ProductReview::RATING_MAX,
                'worstRating' => (string) ProductReview::RATING_MIN,
            ],
            'review' => array_map(static fn (ProductReview $r): array => [
                '@type' => 'Review',
                'author' => ['@type' => 'Person', 'name' => AuthorName::short($r->getCustomer()->getName()) ?: 'Customer'],
                'datePublished' => $r->getCreatedAt()->format('Y-m-d'),
                'reviewBody' => $r->getText(),
                'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (string) $r->getRating(), 'bestRating' => (string) ProductReview::RATING_MAX, 'worstRating' => (string) ProductReview::RATING_MIN],
            ], $this->repository->findApprovedReal($product, self::REVIEWS)),
        ];
    }
}
