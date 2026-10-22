<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Controller;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductReview;
use App\Core\Catalog\Repository\ProductReviewRepository;
use App\Core\Catalog\Review\ProductReviews;
use App\Core\Catalog\Review\ReviewAccess;
use App\Core\Catalog\Review\ReviewLimitException;
use App\Core\Customer\Auth\GoogleSignIn;
use App\Core\Customer\Entity\Customer;
use App\Site\Account\Form\ReviewData;
use App\Site\Account\Form\ReviewType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Відгуки покупця в кабінеті (рішення власника 13.10.2026): «Мої відгуки» (статус, редагування), сторінка нового відгуку
 * (сюди веде «Увійти, щоб залишити відгук» з картки: гість проходить вхід і повертається — entry point тримає target
 * path), подання з inline-форми картки (POST той самий маршрут). Один відгук на товар: повторне подання редагує свій.
 * Усі POST — stateless CSRF форми (token_id submit); маршрути site_account* — no-store/noindex. Ліміт з IP — limiter
 * review_submit_ip, добовий ліміт покупця — ProductReviews.
 */
final class ReviewController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductReviews $reviews,
        private readonly ProductReviewRepository $repository,
        private readonly GoogleSignIn $google,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.review_submit_ip')] private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[IsGranted(Customer::ROLE)]
    public function index(#[CurrentUser] Customer $customer): Response
    {
        if (ReviewAccess::Disabled === $this->reviews->access($customer)) {
            throw $this->createNotFoundException();
        }

        return $this->render('site/account/reviews.html.twig', ['customer' => $customer, 'reviews' => $this->repository->findForCustomer($customer)]);
    }

    /** GET ?product=<id> — форма (новий або свій наявний); POST — подання з цієї сторінки або з картки товару. */
    #[IsGranted(Customer::ROLE)]
    public function new(Request $request, #[CurrentUser] Customer $customer): Response
    {
        $access = $this->reviews->access($customer);
        if (ReviewAccess::Disabled === $access) {
            throw $this->createNotFoundException();
        }
        $productId = $request->isMethod('POST') ? $request->request->all('review')['product'] ?? null : $request->query->get('product');
        $product = is_numeric($productId) ? $this->em->find(Product::class, (int) $productId) : null;
        if (null === $product || !$product->isActive()) {
            throw $this->createNotFoundException();
        }
        $locale = $request->getLocale();
        if (ReviewAccess::GoogleRequired === $access) {
            return $this->render('site/account/review_form.html.twig', [
                'customer' => $customer, 'product' => $product, 'tr' => $product->getTranslation($locale), 'form' => null, 'existing' => null,
                'google_path' => $this->google->startPath($this->generateUrl('site_account_review_new', ['product' => $product->getId()]), $locale),
            ]);
        }
        $existing = $this->repository->findOneByProductAndCustomer($product, $customer);
        $data = null === $existing ? new ReviewData() : ReviewData::of($existing);
        $data->product = (int) $product->getId();
        $form = $this->createForm(ReviewType::class, $data, ['action' => $this->generateUrl('site_account_review_new')]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $saved = $this->save($request, $form, $product, $customer, $data);
            if (null !== $saved) {
                $this->addFlash('success', $this->translator->trans($saved->isApproved() ? 'site.account.reviews.flash.published' : 'site.account.reviews.flash.pending'));

                $slug = $product->getTranslation($locale)?->getSlug();

                return $this->redirect(null === $slug ? $this->generateUrl('site_account_reviews') : $this->generateUrl('site_product', ['slug' => $slug, '_locale' => $locale]).'#reviews');
            }
        }

        return $this->render('site/account/review_form.html.twig', [
            'customer' => $customer, 'product' => $product, 'tr' => $product->getTranslation($locale), 'form' => $form, 'existing' => $existing, 'google_path' => null,
        ]);
    }

    #[IsGranted(Customer::ROLE)]
    public function edit(Request $request, int $id, #[CurrentUser] Customer $customer): Response
    {
        $access = $this->reviews->access($customer);
        if (ReviewAccess::Disabled === $access) {
            throw $this->createNotFoundException();
        }
        $review = $this->repository->findOneForCustomer($customer, $id) ?? throw $this->createNotFoundException();
        $product = $review->getProduct();
        $locale = $request->getLocale();
        if (ReviewAccess::GoogleRequired === $access) {
            return $this->render('site/account/review_form.html.twig', [
                'customer' => $customer, 'product' => $product, 'tr' => $product->getTranslation($locale), 'form' => null, 'existing' => $review,
                'google_path' => $this->google->startPath($this->generateUrl('site_account_review_edit', ['id' => $review->getId()]), $locale),
            ]);
        }
        $data = ReviewData::of($review);
        $form = $this->createForm(ReviewType::class, $data, ['action' => $this->generateUrl('site_account_review_edit', ['id' => $review->getId()])]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $saved = $this->save($request, $form, $product, $customer, $data);
            if (null !== $saved) {
                $this->addFlash('success', $saved->isApproved() ? 'site.account.reviews.flash.published' : 'site.account.reviews.flash.pending');

                return $this->redirectToRoute('site_account_reviews');
            }
        }

        return $this->render('site/account/review_form.html.twig', [
            'customer' => $customer, 'product' => $product, 'tr' => $product->getTranslation($locale), 'form' => $form, 'existing' => $review, 'google_path' => null,
        ]);
    }

    /** @param FormInterface<ReviewData> $form */
    private function save(Request $request, FormInterface $form, Product $product, Customer $customer, ReviewData $data): ?ProductReview
    {
        if (!$this->limiter->create($request->getClientIp() ?? 'anon')->consume()->isAccepted()) {
            $form->addError(new FormError('site.account.reviews.error.too_many'));

            return null;
        }
        try {
            return $this->reviews->submit($product, $customer, (int) $data->rating, $data->text, $request->getLocale());
        } catch (ReviewLimitException) {
            $form->addError(new FormError('site.account.reviews.error.daily_limit'));

            return null;
        }
    }
}
