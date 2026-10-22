<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Controller;

use App\Core\Catalog\Entity\Product;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Wishlist\Wishlist;
use App\Site\Catalog\ProductCardsByIds;
use App\Site\Customer\CustomerContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Обране покупця (E12 PR-10): сторінка в кабінеті, додати/прибрати — POST-форми без JS з кнопки на картці товару
 * й у лістингу, редирект назад на свою сторінку. CSRF `wishlist` stateless (csrf.yaml): форма на кешованій картці
 * не стартує сесію гостю; гостя access_control веде на вхід.
 */
final class WishlistController extends AbstractController
{
    public function __construct(
        private readonly Wishlist $wishlist,
        private readonly ProductCardsByIds $cards,
        private readonly CustomerContext $customers,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[IsGranted(Customer::ROLE)]
    public function index(Request $request, #[CurrentUser] Customer $customer): Response
    {
        $cards = $this->cards->load($this->wishlist->productIds($customer), $request->getLocale(), $this->customers->group());

        return $this->render('site/account/wishlist.html.twig', ['customer' => $customer, 'cards' => $cards]);
    }

    #[IsGranted(Customer::ROLE)]
    public function add(Request $request, #[CurrentUser] Customer $customer): Response
    {
        $this->wishlist->add($customer, $this->product($request));

        return $this->redirect($this->back($request));
    }

    #[IsGranted(Customer::ROLE)]
    public function remove(Request $request, #[CurrentUser] Customer $customer): Response
    {
        $this->wishlist->remove($customer, $this->product($request));

        return $this->redirect($this->back($request));
    }

    private function product(Request $request): Product
    {
        if (!$this->isCsrfTokenValid('wishlist', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недійсний CSRF-токен');
        }
        $product = $this->em->find(Product::class, $request->request->getInt('product'));
        if (null === $product || !$product->isActive()) {
            throw $this->createNotFoundException();
        }

        return $product;
    }

    private function back(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');

        return '' !== $referer && str_starts_with($referer, $request->getSchemeAndHttpHost().'/') ? $referer : $this->generateUrl('site_account_wishlist');
    }
}
