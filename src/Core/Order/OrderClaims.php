<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order;

use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Entity\Customer;
use App\Core\Order\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Lock\LockFactory;

/**
 * Прив'язка гостьових замовлень до покупця (E12 PR-2, консиліум П5): лише за підтвердженим email і лише натисканням
 * самого покупця, масової прив'язки за збігом нема. Беруться замовлення з точно тим самим email і без покупця;
 * замовлення з іншим покупцем не чіпаються (конфлікт розв'язує підтримка). Телефон не доказ (v2: OTP).
 * Під Lock на покупця: два паралельних натискання не прив'яжуть одне замовлення двічі. Журнал security_audit: id замовлень.
 */
final class OrderClaims
{
    public const string METHOD_EMAIL = 'email_verified';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderRepository $orders,
        private readonly LockFactory $locks,
        private readonly AuthAudit $audit,
    ) {
    }

    /** Скільки замовлень покупець може прив'язати (кнопка в кабінеті лише коли > 0). */
    public function available(Customer $customer): int
    {
        return $customer->isEmailVerified() && $customer->isActive() ? $this->orders->countClaimable($customer->getEmail()) : 0;
    }

    /**
     * @return int скільки замовлень прив'язано
     *
     * @throws InvalidArgumentException email не підтверджено або акаунт вимкнено
     */
    public function claim(Customer $customer): int
    {
        if (!$customer->isEmailVerified() || !$customer->isActive()) {
            throw new InvalidArgumentException('Спершу підтвердьте email');
        }
        $lock = $this->locks->createLock('order-claim-'.$customer->getId(), 10);
        if (!$lock->acquire()) {
            return 0;
        }
        try {
            $ids = [];
            $this->em->wrapInTransaction(function () use ($customer, &$ids): void {
                foreach ($this->orders->findClaimable($customer->getEmail()) as $order) {
                    $order->claim($customer, self::METHOD_EMAIL);
                    $ids[] = (int) $order->getId();
                }
            });
        } finally {
            $lock->release();
        }
        if ([] !== $ids) {
            $this->audit->log('orders_claimed', $customer->getEmail(), $customer->getId(), ['method' => self::METHOD_EMAIL, 'count' => \count($ids), 'order_ids' => implode(',', $ids)]);
        }

        return \count($ids);
    }
}
