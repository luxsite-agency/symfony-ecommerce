<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Account\Controller;

use App\Core\Customer\Entity\Customer;
use App\Core\Order\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Кабінет покупця (E12). Доступ — access_control і #[IsGranted] на дії (рев'ю #40): маршрут без префікса /account не лишиться відкритим. */
final class AccountController extends AbstractController
{
    public function __construct(private readonly OrderRepository $orders)
    {
    }

    /** Головна кабінету: над посиланнями — останнє замовлення (номер, стан, сума; UX-аудит S15). */
    #[IsGranted(Customer::ROLE)]
    public function index(#[CurrentUser] Customer $customer): Response
    {
        return $this->render('site/account/index.html.twig', [
            'customer' => $customer,
            'last_order' => $this->orders->findForCustomer($customer, 1, 1)[0] ?? null,
        ]);
    }
}
