<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Customer;

use App\Core\Customer\CurrentCustomer;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerGroup;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Покупець і його група для ціноутворення в Site-шарі (консиліум 07.10.2026, П3): читає security-токен firewall
 * site і віддає ?CustomerGroup, яку контролери й презентери явно передають у Pricing, CartCalculator
 * і PlaceOrderRequest. Група є лише в активного покупця; provider перечитує Customer щозапиту, тож зміна групи
 * в адмінці діє з наступного запиту. Core (Pricing, PlaceOrder, фід, CLI, Messenger) токен не читає ніколи:
 * PlaceOrder отримує customerId і сам перечитує покупця в транзакції.
 * Гість без cookie сесії і без remember_me (консиліум п. 8: картка товару не стартує сесію) security не торкається:
 * lazy firewall при читанні токена піднімає сесію, і сторінка стала б private з Set-Cookie для кожного бота.
 */
#[AsAlias(CurrentCustomer::class)]
final class CustomerContext implements CurrentCustomer
{
    /** Назва cookie remember_me firewall site (config/packages/security.yaml). */
    public const string REMEMBER_ME_COOKIE = 'SHOP_REMEMBER';

    public function __construct(private readonly Security $security, private readonly RequestStack $requests)
    {
    }

    public function customer(): ?Customer
    {
        $request = $this->requests->getCurrentRequest();
        if (null !== $request && !$request->hasPreviousSession() && !$request->cookies->has(self::REMEMBER_ME_COOKIE)) {
            return null;
        }
        $user = $this->security->getUser();

        return $user instanceof Customer && $user->isActive() ? $user : null;
    }

    public function customerId(): ?int
    {
        return $this->customer()?->getId();
    }

    /** Група для цін: null — роздріб (гість, покупець без групи чи вимкнений). */
    public function group(): ?CustomerGroup
    {
        return $this->customer()?->getCustomerGroup();
    }
}
