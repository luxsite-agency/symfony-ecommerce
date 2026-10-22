<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Customer\Auth;

use App\Core\B2b\Entity\CompanyMember;
use App\Core\Customer\Entity\Customer;
use App\Core\Order\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Хто «володіє» адресою email (рев'ю #60 v2, M1/L3). Реєстрація створює рядок Customer одразу, до підтвердження листом,
 * тож будь-хто може «застовпити» чужу адресу неперевіреним акаунтом: після зміни email зловмисником власник не зміг би
 * повернути стару адресу (`email-revert`), а власник акаунта — змінити адресу на таку «зайняту». Неперевірений рядок без
 * замовлень і членства в компанії — заготовка, не власник: її можна видалити (токени, згоди, обране — каскадом у БД), і
 * адреса вільна. Перевірена адреса чи заготовка з замовленнями/компанією — справжній власник, адресу не чіпаємо.
 */
final class EmailOwnership
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Адресу тримає справжній власник (перевірений акаунт або заготовка з замовленнями чи компанією). */
    public function isOwned(string $email): bool
    {
        $customer = $this->em->getRepository(Customer::class)->findOneBy(['email' => Customer::normalizeEmail($email)]);

        return null !== $customer && !$this->isPlaceholder($customer);
    }

    /**
     * Видаляє заготовку з цією адресою, якщо вона є; викликати всередині транзакції перед зміною адреси. DQL, а не remove():
     * видалення має виконатись до UPDATE customer.email у flush, інакше унікальний індекс спрацює раніше.
     *
     * @return int|null id видаленої заготовки
     */
    public function releasePlaceholder(string $email): ?int
    {
        $customer = $this->em->getRepository(Customer::class)->findOneBy(['email' => Customer::normalizeEmail($email)]);
        if (null === $customer || !$this->isPlaceholder($customer)) {
            return null;
        }
        $id = $customer->getId();
        $this->em->createQuery('DELETE FROM '.Customer::class.' c WHERE c.id = :id')->setParameter('id', $id)->execute();
        $this->em->detach($customer);

        return $id;
    }

    private function isPlaceholder(Customer $customer): bool
    {
        if ($customer->isEmailVerified() || $customer->isDeleted()) {
            return false;
        }
        $orders = (int) $this->em->createQuery('SELECT COUNT(o.id) FROM '.Order::class.' o WHERE o.customer = :c')->setParameter('c', $customer)->getSingleScalarResult();
        $members = (int) $this->em->createQuery('SELECT COUNT(m.id) FROM '.CompanyMember::class.' m WHERE m.customer = :c')->setParameter('c', $customer)->getSingleScalarResult();

        return 0 === $orders && 0 === $members;
    }
}
