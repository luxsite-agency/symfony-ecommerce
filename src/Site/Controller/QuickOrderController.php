<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Exception\OrderAlreadyPlacedException;
use App\Core\Order\Exception\OrderValidationException;
use App\Core\Order\Exception\PriceChangedException;
use App\Core\Order\OrderSubmitGuard;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Site\Customer\CustomerContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Швидке замовлення в один клік з картки (ADR D8): ім'я + телефон, один варіант; доставка/оплата = manager_contact
 * (узгодить менеджер). Помилки — flash + повернення на картку; успіх — «дякуємо» з purchase (generate_lead — лише для
 * форм без замовлення, інакше подвійний облік; рев'ю PR #6 п. 3). Форма несе ціну картки обраного варіанта
 * (price[<id>]); змінилась до submit — замовлення нема, flash з новою ціною, на картці покупець замовляє ще раз.
 */
final class QuickOrderController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlaceOrder $placeOrder,
        private readonly OrderSubmitGuard $submitGuard,
        private readonly TranslatorInterface $translator,
        private readonly CustomerContext $customers,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('quick_order', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недійсний CSRF-токен');
        }
        $this->submitGuard->check($request, (string) $request->request->get('phone', ''));
        $variant = $this->em->find(ProductVariant::class, $request->request->getInt('variant')) ?? throw $this->createNotFoundException();
        $quantity = (string) $request->request->get('quantity', '1');
        $seen = $request->request->all('price')[$variant->getId()] ?? null; // ціна картки, яку бачив покупець; нема — підтвердити
        $back = (string) $request->headers->get('referer', '');
        if ('' === $back || !str_starts_with($back, $request->getSchemeAndHttpHost().'/')) {
            $back = $this->generateUrl('site_home');
        }
        try {
            $order = $this->placeOrder->place(new PlaceOrderRequest(
                source: OrderSource::Quick,
                locale: $request->getLocale(),
                customerName: (string) $request->request->get('name', ''),
                phone: (string) $request->request->get('phone', ''),
                lines: [['variant' => $variant, 'quantity' => $quantity, 'card_price' => is_numeric($seen) ? (int) $seen : null]],
                idempotencyKey: (string) $request->request->get('key', ''),
                ip: $request->getClientIp(),
                customerId: $this->customers->customerId(),
            ));
        } catch (OrderAlreadyPlacedException $e) {
            return $this->redirectToRoute('site_order_thanks', ['token' => $e->successToken]);
        } catch (PriceChangedException $e) {
            $this->addFlash('error', $this->translator->trans('site.quick.price_changed', ['price' => $e->total->format($request->getLocale())]));

            return $this->redirect($back);
        } catch (OrderValidationException $e) {
            foreach ($e->errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirect($back);
        }

        return $this->redirectToRoute('site_order_thanks', ['token' => $order->getSuccessToken()]);
    }
}
