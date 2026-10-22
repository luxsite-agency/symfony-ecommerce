<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Catalog;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductReview;
use App\Core\Catalog\Repository\ProductReviewRepository;
use App\Core\Catalog\Review\AuthorName;
use App\Core\Catalog\Review\ProductRatings;
use App\Core\Catalog\Review\ProductReviews;
use App\Core\Catalog\Review\RatingSummary;
use App\Core\Catalog\Review\ReviewAccess;
use App\Core\Catalog\Review\ReviewSettings;
use App\Core\Customer\Auth\GoogleSignIn;
use App\Core\Customer\Entity\Customer;
use App\Core\Demo\DemoTexts;
use App\Site\Account\Form\ReviewData;
use App\Site\Account\Form\ReviewType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Блок відгуків на картці товару (partial site/partials/_product_reviews.html.twig): агрегат (зірки, кількість), схвалені
 * відгуки сторінками (?reviews_page=N, нові зверху; демо-відгуки з позначкою), відповідь магазину, «купив цей товар»,
 * підпис «Ім'я П.», і що показати замість форми: форму (покупець увійшов), «увійти, щоб залишити відгук» зі звичайним
 * входом і кнопкою Google (гість), «увійдіть через Google» (require_google). Гостю на кешованій картці Security не
 * торкається — покупця дає контролер з CustomerContext. Вимкнено (app.reviews.enabled = false) — null: блоку нема.
 */
final class ProductReviewsBlock
{
    public const string PAGE_PARAM = 'reviews_page';

    public function __construct(
        private readonly ProductReviewRepository $repository,
        private readonly ProductRatings $ratings,
        private readonly ProductReviews $reviews,
        private readonly ReviewSettings $settings,
        private readonly GoogleSignIn $google,
        private readonly FormFactoryInterface $forms,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array{summary: ?RatingSummary, items: list<array<string, mixed>>, page: int, pages: int, access: string, form: ?FormView, mine: ?ProductReview, login_path: ?string, google_path: ?string}|null
     */
    public function build(Product $product, string $locale, int $page, ?Customer $customer): ?array
    {
        if (!$this->settings->enabled) {
            return null;
        }
        $total = $this->repository->countApproved($product);
        $pages = max(1, (int) ceil($total / $this->settings->pageSize));
        $page = min(max(1, $page), $pages);
        $items = array_map(static fn (ProductReview $review): array => self::item($review, $locale), $this->repository->findApproved($product, $page, $this->settings->pageSize));

        $access = $this->reviews->access($customer);
        $next = $this->urls->generate('site_account_review_new', ['product' => $product->getId(), '_locale' => $locale]);
        $form = null;
        $mine = null;
        if (ReviewAccess::Allowed === $access && null !== $customer) {
            $mine = $this->repository->findOneByProductAndCustomer($product, $customer);
            $data = null === $mine ? new ReviewData() : ReviewData::of($mine);
            $data->product = (int) $product->getId();
            $form = $this->forms->create(ReviewType::class, $data, ['action' => $this->urls->generate('site_account_review_new', ['_locale' => $locale])])->createView();
        }

        return [
            'summary' => $this->ratings->forProduct((int) $product->getId()),
            'items' => $items,
            'page' => $page,
            'pages' => $pages,
            'access' => $access->value,
            'form' => $form,
            'mine' => $mine,
            'login_path' => ReviewAccess::LoginRequired === $access ? $next : null,
            'google_path' => \in_array($access, [ReviewAccess::LoginRequired, ReviewAccess::GoogleRequired], true) && $this->google->isAvailable() ? $this->google->startPath($next, $locale) : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function item(ProductReview $review, string $locale): array
    {
        return [
            'id' => $review->getId(),
            'author' => AuthorName::short($review->getCustomer()->getName()),
            'rating' => $review->getRating(),
            // демо-відгук — мовою вітрини (DemoTexts::reviews), справжній — як написав покупець
            'text' => $review->isDemo() ? DemoTexts::reviewText($review->getText(), $locale) : $review->getText(),
            'date' => $review->getCreatedAt(),
            'verified' => $review->isVerifiedPurchase(),
            'demo' => $review->isDemo(),
            'reply' => $review->hasReply() ? ['text' => $review->getReplyText(), 'by' => $review->getReplyBy(), 'date' => $review->getRepliedAt()] : null,
        ];
    }
}
