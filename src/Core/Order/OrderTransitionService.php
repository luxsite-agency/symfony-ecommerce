<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Order;

use App\Core\Order\Entity\Order;
use App\Core\Order\Entity\OrderStatusHistory;
use App\Core\Order\Exception\TransitionUnavailable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\Exception\LogicException;
use Symfony\Component\Workflow\WorkflowInterface;
use Throwable;

/**
 * Єдина точка переходів (ADR D6): перевірка Workflow, зміна стану, запис історії, при cancel — повернення
 * зарезервованого залишку (ADR D7: restore лише в cancel). Автоскасування pending-оплати — не тут (лише онлайн-оплата, Ф3).
 */
final class OrderTransitionService
{
    public function __construct(
        #[Target('order')] private readonly WorkflowInterface $workflow,
        private readonly EntityManagerInterface $em,
        private readonly StockReservation $stock,
    ) {
    }

    /** @return list<string> доступні переходи для кнопок адмінки */
    public function available(Order $order): array
    {
        return array_map(static fn ($t): string => $t->getName(), $this->workflow->getEnabledTransitions($order));
    }

    public function can(Order $order, string $transition): bool
    {
        return $this->workflow->can($order, $transition);
    }

    /**
     * Перехід у транзакції з PESSIMISTIC_WRITE на замовленні (консиліум PR #6 п. 3): паралельний cancel (подвійний клік)
     * після блокування перечитує стан і бачить, що перехід уже зроблено — резерв не повертається двічі; release і flush
     * в одній транзакції, тож збій flush відкочує й повернення залишку.
     *
     * @throws LogicException якщо перехід недоступний
     */
    public function apply(Order $order, string $transition, string $actor = 'system', ?string $comment = null): void
    {
        // не wrapInTransaction: він закриває EntityManager на будь-якому винятку, а «перехід недоступний» — штатна відмова
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->lock($order, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($order);
            if (!$this->workflow->can($order, $transition)) {
                // в адмінці — фразою мовою адмінки без кодів переходу (TranslatableException), у логах — як раніше
                throw new TransitionUnavailable($transition, $order->getStatus(), $order->getNumber());
            }
            $from = $order->getStatus();
            $this->workflow->apply($order, $transition);
            if ('cancel' === $transition) {
                foreach ($order->getItems() as $item) {
                    if (null !== $item->getVariant() && 0 !== bccomp($item->getReservedStock(), '0', 3)) {
                        $this->stock->release($item->getVariant(), $item->getReservedStock());
                        $item->markStockReleased();
                    }
                }
            }
            $order->addHistory(new OrderStatusHistory($order, $from, $order->getStatus(), $transition, $actor, $comment));
            $this->em->flush();
            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }
}
