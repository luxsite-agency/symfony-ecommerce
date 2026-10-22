<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Controller;

use App\Core\Cart\CartResolver;
use App\Core\Cart\Exception\CartCreationRace;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Catalog\Option\InvalidOptionsException;
use App\Core\Customer\Entity\Customer;
use App\Core\Order\Entity\Order;
use App\Core\Order\OrderClaims;
use App\Core\Order\Repository\OrderRepository;
use App\Core\Pricing\Pricing;
use App\Site\Customer\CustomerContext;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Історія замовлень у кабінеті (E12 PR-2): лише свої (WHERE customer = :me; чуже — 404, не 403), деталі зі статусом,
 * «повторити» в кошик за поточними цінами (опції перераховуються фасадом Pricing, недоступні позиції пропускаються
 * з повідомленням) і прив'язка гостьових замовлень за підтвердженим email натисканням покупця (OrderClaims).
 * POST-и зі stateless CSRF `account`.
 */
final class OrderHistoryController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderClaims $claims,
        private readonly CartResolver $carts,
        private readonly Pricing $pricing,
        private readonly CustomerContext $customers,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[IsGranted(Customer::ROLE)]
    public function index(Request $request, #[CurrentUser] Customer $customer): Response
    {
        $total = $this->orders->countForCustomer($customer);
        $pages = max(1, (int) ceil($total / OrderRepository::PAGE_SIZE));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));

        return $this->render('site/account/orders.html.twig', [
            'customer' => $customer,
            'orders' => $this->orders->findForCustomer($customer, $page),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'claimable' => $this->claims->available($customer),
        ]);
    }

    #[IsGranted(Customer::ROLE)]
    public function show(int $id, #[CurrentUser] Customer $customer): Response
    {
        return $this->render('site/account/order.html.twig', ['customer' => $customer, 'order' => $this->own($id, $customer)]);
    }

    /** «Знайти мої замовлення за цим email»: лише підтверджений email, лише нічиї замовлення з точно цією адресою. */
    #[IsGranted(Customer::ROLE)]
    public function claim(Request $request, #[CurrentUser] Customer $customer): Response
    {
        $this->assertCsrf($request);
        // вимкнений під час живої сесії: #[CurrentUser] його ще віддає, а OrderClaims кинув би виняток (500 замість 403)
        if (!$customer->isEmailVerified() || !$customer->isActive()) {
            throw $this->createAccessDeniedException('Email не підтверджено або акаунт вимкнено');
        }
        $count = $this->claims->claim($customer);
        $this->addFlash($count > 0 ? 'success' : 'error', $this->translator->trans($count > 0 ? 'site.account.orders.flash.claimed' : 'site.account.orders.flash.nothing_to_claim', ['count' => $count]));

        return $this->redirectToRoute('site_account_orders');
    }

    /** Повторити замовлення: позиції в кошик за поточними цінами; знятий з продажу чи невалідний вибір — пропуск з переліком. */
    #[IsGranted(Customer::ROLE)]
    public function reorder(Request $request, int $id, #[CurrentUser] Customer $customer): Response
    {
        $this->assertCsrf($request);
        $order = $this->own($id, $customer);
        $group = $this->customers->group();
        $added = 0;
        $skipped = [];
        foreach ($order->getItems() as $item) {
            if (null !== $item->getParent()) {
                continue; // компоненти наборів кладе сам снепшот опцій головного рядка
            }
            $variant = $item->getVariant();
            if (null === $variant || !$variant->isActive() || !$variant->getProduct()->isActive() || !$variant->isOrderable()) {
                $skipped[] = $item->getName();
                continue;
            }
            try {
                // збережений вибір опцій перераховується за поточним каталогом і цінами (фасад Pricing, група покупця)
                $line = $this->pricing->line($variant, $item->getQuantity(), $item->getOptionsSnapshot(), $group, $request->getLocale());
                $this->carts->currentOrCreate()->addItem($variant, $item->getQuantity(), $line->configuration?->snapshot);
                ++$added;
            } catch (CatalogInvariantViolation|InvalidOptionsException|InvalidArgumentException) {
                $skipped[] = $item->getName();
            } catch (CartCreationRace) {
                // подвійний клік «Повторити» без кошика: позиції кладе перший запит — показуємо кошик
                return $this->redirectToRoute('site_cart');
            }
        }
        if ($added > 0) {
            $this->em->flush();
            $this->addFlash('success', $this->translator->trans('site.account.orders.flash.reordered', ['count' => $added]));
        }
        if ([] !== $skipped) {
            $this->addFlash('error', $this->translator->trans('site.account.orders.flash.unavailable', ['names' => implode(', ', $skipped)]));
        }

        return $added > 0 ? $this->redirectToRoute('site_cart') : $this->redirectToRoute('site_account_order', ['id' => $order->getId()]);
    }

    private function own(int $id, Customer $customer): Order
    {
        return $this->orders->findOneForCustomer($id, $customer) ?? throw $this->createNotFoundException();
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('account', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Недійсний CSRF-токен');
        }
    }
}
