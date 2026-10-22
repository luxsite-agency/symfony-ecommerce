<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Core\Analytics\DataLayerCollector;
use App\Core\Analytics\Ecommerce\EcommerceEventBuilder;
use App\Core\Cart\CartCalculator;
use App\Core\Cart\CartResolver;
use App\Core\Cart\CartTotals;
use App\Core\Cart\Exception\CartCreationRace;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Order\Minimum\OrderMinimum;
use App\Core\Pricing\Pricing;
use App\Site\Customer\CustomerContext;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Кошик без JS (ADR D11): HTML-форми з CSRF, PRG-редиректи; JSON — для міні-кошика й dataLayer. Маршрути —
 * SiteRouteLoader (site_cart, site_cart_add/update/remove, site_cart_json). Події add_to_cart/remove_from_cart
 * ставляться в чергу dataLayer і виходять на наступній сторінці після редиректу. Група покупця — з CustomerContext,
 * явно в кожен виклик Pricing/CartCalculator (оптовик бачить свої ціни в кошику й у dataLayer).
 */
final class CartController extends AbstractController
{
    public function __construct(
        private readonly CartResolver $carts,
        private readonly CartCalculator $calculator,
        private readonly EntityManagerInterface $em,
        private readonly DataLayerCollector $dataLayer,
        private readonly EcommerceEventBuilder $events,
        private readonly TranslatorInterface $translator,
        private readonly Pricing $pricing,
        private readonly CustomerContext $customers,
        private readonly OrderMinimum $minimum,
    ) {
    }

    public function index(Request $request): Response
    {
        $cart = $this->carts->current();
        $totals = null === $cart ? null : $this->calculator->calculate($cart, $this->customers->group());
        if (null !== $totals && $totals->count() > 0) {
            $this->dataLayer->push($this->events->viewCart($this->items($totals, $request->getLocale())));
        }

        $shortfall = null === $totals ? null : $this->minimum->shortfall($this->customers->group(), $totals->itemsTotal, $totals->totalQuantity());

        return $this->render('site/cart/index.html.twig', ['cart' => $cart, 'totals' => $totals, 'shortfall' => $shortfall]);
    }

    public function add(Request $request): Response
    {
        $this->assertCsrf($request);
        $variant = $this->em->find(ProductVariant::class, $request->request->getInt('variant'));
        if (null === $variant) {
            throw $this->createNotFoundException();
        }
        $quantity = (string) $request->request->get('quantity', '1');
        try {
            // опції з форми картки (options[код групи] = код | [коди] | число) — розрахунок і снепшот (ADR Rev 9)
            $input = $request->request->all()['options'] ?? [];
            $line = $this->pricing->configure($variant, $quantity, \is_array($input) ? $input : [], $request->getLocale(), $this->customers->group());
            $cart = $this->carts->currentOrCreate();
            $cart->addItem($variant, $quantity, $line->configuration?->snapshot);
            $this->em->flush();
        } catch (CatalogInvariantViolation|InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirect($this->back($request));
        } catch (CartCreationRace) {
            // подвійний клік без кошика: рядок уже поклав перший запит — просто показуємо кошик
            return $this->redirectToRoute('site_cart');
        }
        // add_to_cart — та сама ціна рядка, що в кошику: опції + акція (знижка GA4 = до акції − до сплати)
        $this->dataLayer->queue($this->events->addToCart([$this->events->item($variant, $quantity, null, null, $request->getLocale(), $line->quote->price, $line->quote->listPrice)]));
        $this->addFlash('success', $this->translator->trans('site.cart.added', ['name' => (string) $variant->getProduct()]));

        return $this->redirectToRoute('site_cart');
    }

    public function update(Request $request): Response
    {
        $this->assertCsrf($request);
        $cart = $this->carts->current() ?? throw $this->createNotFoundException();
        $item = $cart->findItemById($request->request->getInt('item')) ?? throw $this->createNotFoundException();
        try {
            $item->setQuantity((string) $request->request->get('quantity', '1'));
            $this->em->flush();
        } catch (InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('site_cart');
    }

    public function remove(Request $request): Response
    {
        $this->assertCsrf($request);
        $cart = $this->carts->current() ?? throw $this->createNotFoundException();
        $item = $cart->findItemById($request->request->getInt('item'));
        if (null !== $item) {
            $quote = $this->pricing->quote($item->getVariant(), $item->getQuantity(), $this->customers->group());
            $this->dataLayer->queue($this->events->removeFromCart([$this->events->item($item->getVariant(), $item->getQuantity(), null, null, $request->getLocale(), $quote->price, $quote->listPrice)]));
            $cart->removeItem($item);
            $this->em->flush();
        }

        return $this->redirectToRoute('site_cart');
    }

    /** JSON для міні-кошика та JS: рядки, сума і готовий payload view_cart для dataLayer. */
    public function summary(Request $request): JsonResponse
    {
        $cart = $this->carts->current();
        $totals = null === $cart ? new CartTotals([], \App\Core\Shared\Money\Money::zero($this->getParameter('app.default_currency'))) : $this->calculator->calculate($cart, $this->customers->group());
        $items = $this->items($totals, $request->getLocale());

        return new JsonResponse([
            'count' => $totals->count(),
            'currency' => $totals->itemsTotal->currency,
            'itemsTotal' => $totals->itemsTotal->toDecimalString(),
            'lines' => array_map(static fn ($line): array => [
                'id' => $line->item->getId(),
                'sku' => $line->item->getVariant()->getSku(),
                'name' => (string) $line->item->getVariant()->getProduct(),
                'quantity' => $line->item->getQuantity(),
                'unitPrice' => $line->unitPrice->toDecimalString(),
                'lineTotal' => $line->lineTotal->toDecimalString(),
                'problem' => $line->problem,
            ], $totals->lines),
            'dataLayer' => [] === $items ? null : $this->events->viewCart($items),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function items(CartTotals $totals, string $locale): array
    {
        $items = [];
        foreach ($totals->lines as $line) {
            $items[] = $this->events->item($line->item->getVariant(), $line->item->getQuantity(), null, null, $locale, $line->unitPrice, $line->listPrice ?? $line->unitPrice);
        }

        return $items;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('cart', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недійсний CSRF-токен');
        }
    }

    private function back(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');

        return '' !== $referer && str_starts_with($referer, $request->getSchemeAndHttpHost().'/') ? $referer : $this->generateUrl('site_cart');
    }
}
