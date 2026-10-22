<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Form;

use App\Core\Catalog\Entity\ProductReview;
use App\Core\Catalog\Review\ReviewText;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Форма відгуку: товар (id), оцінка 1–5, текст без HTML і посилань (ReviewText — відхиляємо, не правимо). */
final class ReviewData
{
    #[Assert\Positive(message: 'site.account.reviews.error.product')]
    public int $product = 0;

    #[Assert\NotBlank(message: 'site.account.reviews.error.rating')]
    #[Assert\Range(min: ProductReview::RATING_MIN, max: ProductReview::RATING_MAX, notInRangeMessage: 'site.account.reviews.error.rating')]
    public ?int $rating = null;

    #[Assert\NotBlank(message: 'site.account.reviews.error.text_blank', normalizer: 'trim')]
    #[Assert\Length(max: ProductReview::TEXT_MAX, maxMessage: 'site.account.reviews.error.text_long')]
    public string $text = '';

    #[Assert\Callback]
    public function validateText(ExecutionContextInterface $context): void
    {
        $problem = ReviewText::problem($this->text);
        if (null !== $problem) {
            $context->buildViolation('site.account.reviews.error.text_'.$problem)->atPath('text')->addViolation();
        }
    }

    public static function of(ProductReview $review): self
    {
        $data = new self();
        $data->product = (int) $review->getProduct()->getId();
        $data->rating = $review->getRating();
        $data->text = $review->getText();

        return $data;
    }
}
