<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Account;

use App\Core\B2b\Entity\CompanyMember;
use App\Core\Cart\Entity\Cart;
use App\Core\Catalog\Review\ProductReviews;
use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Auth\AuthTokens;
use App\Core\Customer\Consent\Consents;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\ViewedProduct;
use App\Core\Customer\Entity\WishlistItem;
use App\Core\Customer\Enum\ConsentPurpose;
use App\Core\Customer\Repository\CustomerAddressRepository;
use App\Core\Order\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Видалення акаунта на запит покупця (E12 PR-6; рішення власника 11.10.2026: дані зберігаються постійно, тож
 * «видалити» = стерти ідентичність і закрити вхід). Повторну автентифікацію паролем робить контролер. Тут, в одній
 * транзакції: адреси видаляються, членство в компанії знімається, невикористані токени гаснуть, у замовленнях
 * customer_id → NULL (снепшоти замовлень лишаються на законний строк), згоди — події «відкликано», Customer::erase()
 * (email, пароль, ім'я, телефон → NULL, active = false, authVersion++ → сесії й remember_me недійсні). Журнал
 * security_audit: account_deleted за id. Обране й переглянуті видаляються, кошик відвʼязується (customer_id → NULL, живе
 * до свого TTL без власника): рядок покупця лишається, тож каскад БД не спрацював би (рев'ю #60 v2, L2). Відгуки на товари —
 * особистий контент, стираються (ProductReviews::deleteAllOf) з перерахунком оцінок товарів.
 */
final class AccountDeletion
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CustomerAddressRepository $addresses,
        private readonly AuthTokens $tokens,
        private readonly Consents $consents,
        private readonly AuthAudit $audit,
        private readonly ProductReviews $reviews,
    ) {
    }

    public function delete(Customer $customer, string $source = 'delete'): void
    {
        $email = $customer->getEmail();
        $this->em->wrapInTransaction(function () use ($customer, $source): void {
            $this->addresses->deleteAllOf($customer);
            $this->reviews->deleteAllOf($customer);
            foreach ([CompanyMember::class, WishlistItem::class, ViewedProduct::class] as $class) {
                $this->em->createQueryBuilder()->delete($class, 'x')->andWhere('x.customer = :c')->setParameter('c', $customer)->getQuery()->execute();
            }
            $this->em->createQueryBuilder()->update(Cart::class, 'k')->set('k.customer', ':none')->andWhere('k.customer = :c')
                ->setParameter('none', null)->setParameter('c', $customer)->getQuery()->execute();
            $this->em->createQueryBuilder()->update(Order::class, 'o')->set('o.customer', ':none')->andWhere('o.customer = :c')
                ->setParameter('none', null)->setParameter('c', $customer)->getQuery()->execute();
            $this->tokens->revokeAll($customer);
            foreach (ConsentPurpose::cases() as $purpose) {
                $this->consents->record($customer, $purpose, false, $source);
            }
            $customer->erase();
            $this->em->flush();
        });
        $this->audit->log('account_deleted', $email, $customer->getId(), ['source' => $source]);
    }
}
