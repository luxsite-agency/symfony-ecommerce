<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Review;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductReview;
use App\Core\Catalog\Enum\ReviewStatus;
use App\Core\Catalog\Repository\ProductReviewRepository;
use App\Core\Customer\Auth\GoogleSignIn;
use App\Core\Customer\Entity\Customer;
use App\Core\Demo\DemoAccounts;
use App\Core\Order\Enum\OrderStatus;
use App\Core\Security\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Відгуки на товари (рішення власника 13.10.2026). Хто пише — лише покупець, що увійшов (будь-який спосіб входу; Google —
 * швидкий шлях); з app.reviews.require_google — лише прив'язані до Google, демо-акаунти завжди. Один відгук на товар:
 * повторне подання редагує свій і повертає його на модерацію. Премодерація за замовчуванням. «Купив цей товар» —
 * виконане замовлення покупця з варіантом цього товару на момент збереження. Після кожної зміни стану —
 * перерахунок агрегату ProductRatings (зірки на картці й у лістингу).
 */
final class ProductReviews
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly ProductReviewRepository $reviews,
        private readonly ProductRatings $ratings,
        private readonly ReviewSettings $settings,
        private readonly GoogleSignIn $google,
        private readonly DemoAccounts $demo,
    ) {
    }

    public function access(?Customer $customer): ReviewAccess
    {
        if (!$this->settings->enabled) {
            return ReviewAccess::Disabled;
        }
        if (null === $customer) {
            return ReviewAccess::LoginRequired;
        }
        // демо-акаунт пише завжди — лише в увімкненому демо-режимі (DemoAccounts::isDemo дивиться тільки на адресу)
        if ($this->settings->requireGoogle && !($this->demo->isEnabled() && $this->demo->isDemo($customer)) && !$this->google->isLinked($customer)) {
            return ReviewAccess::GoogleRequired;
        }

        return ReviewAccess::Allowed;
    }

    /**
     * Новий відгук або редагування свого (один на товар). Текст уже перевірений ReviewText (форма); тут — лише обрізка.
     *
     * @throws ReviewLimitException добовий ліміт нових відгуків
     */
    public function submit(Product $product, Customer $customer, int $rating, string $text, string $locale): ProductReview
    {
        $text = ReviewText::normalize($text);
        $review = $this->reviews->findOneByProductAndCustomer($product, $customer);
        if (null === $review) {
            if ($this->reviews->countCreatedSince($customer, new DateTimeImmutable('-24 hours')) >= $this->settings->dailyLimit) {
                throw new ReviewLimitException('Добовий ліміт відгуків вичерпано');
            }
            $review = new ProductReview($product, $customer, $rating, $text, $locale);
            if ($this->demo->isDemo($customer)) {
                $review->markDemo();
            }
            $this->em->persist($review);
        } else {
            $review->setRating($rating);
            $review->setText($text);
            $review->setLocale($locale);
        }
        $review->setVerifiedPurchase($this->hasCompletedOrderWith($customer, $product));
        $review->resubmit($this->settings->premoderation);
        // подвійне подання тієї ж миті впирається в unique(product, customer): друге впаде, перше збережено — покупець бачить свій відгук
        $this->em->flush();
        $this->ratings->refresh((int) $product->getId());

        return $review;
    }

    public function approve(ProductReview $review, ?User $by): void
    {
        $review->moderate(ReviewStatus::Approved, $by?->getDisplayName());
        $this->em->flush();
        $this->ratings->refresh((int) $review->getProduct()->getId());
    }

    public function reject(ProductReview $review, ?User $by): void
    {
        $review->moderate(ReviewStatus::Rejected, $by?->getDisplayName());
        $this->em->flush();
        $this->ratings->refresh((int) $review->getProduct()->getId());
    }

    public function reply(ProductReview $review, ?string $text, ?User $by): void
    {
        $review->reply($text, $by);
        $this->em->flush();
    }

    public function delete(ProductReview $review): void
    {
        $productId = (int) $review->getProduct()->getId();
        $this->em->remove($review);
        $this->em->flush();
        $this->ratings->refresh($productId);
    }

    /** Видалення акаунта: відгуки — особистий контент, стираються; агрегати товарів перераховуються. */
    public function deleteAllOf(Customer $customer): void
    {
        /** @var list<int> $productIds */
        $productIds = array_map('intval', $this->db->fetchFirstColumn('SELECT product_id FROM catalog_product_review WHERE customer_id = ?', [$customer->getId()]));
        $this->em->createQueryBuilder()->delete(ProductReview::class, 'r')->andWhere('r.customer = :c')->setParameter('c', $customer)->getQuery()->execute();
        $this->ratings->refreshMany($productIds);
    }

    /** Виконане замовлення покупця з варіантом цього товару (рядок замовлення тримає variant_id; варіант видалений — не рахується). */
    public function hasCompletedOrderWith(Customer $customer, Product $product): bool
    {
        return false !== $this->db->fetchOne(
            'SELECT 1 FROM sales_order o JOIN sales_order_item i ON i.order_id = o.id JOIN catalog_product_variant v ON v.id = i.variant_id
             WHERE o.customer_id = ? AND o.status = ? AND v.product_id = ? LIMIT 1',
            [$customer->getId(), OrderStatus::Completed->value, $product->getId()],
        );
    }
}
