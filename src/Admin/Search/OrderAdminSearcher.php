<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Search;

use App\Core\B2b\Entity\CompanyMember;
use App\Core\Catalog\Search\ProductTextMatch;
use App\Core\Order\Entity\Order;
use App\Core\Order\Entity\OrderItem;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Замовлення: кожне слово — у номері («112» → …-000112), імені покупця, email, телефоні (цифри без пробілів і дужок;
 * провідний 0 відкидається, тож «050 111 22 33» знаходить +380501112233, а «0412 345 678» — +61412345678), назві компанії
 * покупця або SKU позиції (за префіксом). Таблиця замовлень невелика — LIKE з індексами телефону/email/номера де можна.
 */
final class OrderAdminSearcher implements AdminSearcher
{
    private const int NUMBER_DIGITS = 6;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function supports(string $entityFqcn): bool
    {
        return is_a($entityFqcn, Order::class, true);
    }

    public function apply(QueryBuilder $qb, array $terms): void
    {
        foreach ($terms as $i => $term) {
            $like = '%'.ProductTextMatch::escapeLike($term).'%';
            $or = $qb->expr()->orX(
                "LOWER(entity.customerName) LIKE :ss_t$i",
                "LOWER(entity.email) LIKE :ss_t$i",
                "LOWER(entity.number) LIKE :ss_t$i",
                $qb->expr()->exists($this->em->createQueryBuilder()->select('ss_m'.$i)->from(CompanyMember::class, 'ss_m'.$i)
                    ->join('ss_m'.$i.'.company', 'ss_c'.$i)
                    ->where('ss_m'.$i.'.customer = entity.customer')->andWhere("LOWER(ss_c$i.name) LIKE :ss_t$i")->getDQL()),
                $qb->expr()->exists($this->em->createQueryBuilder()->select('ss_i'.$i)->from(OrderItem::class, 'ss_i'.$i)
                    ->where('ss_i'.$i.'.order = entity')->andWhere("LOWER(ss_i$i.sku) LIKE :ss_s$i")->getDQL()),
            );
            $qb->setParameter('ss_t'.$i, $like)->setParameter('ss_s'.$i, ProductTextMatch::escapeLike($term).'%');
            $digits = AdminSearchQuery::digits($term);
            if (null !== $digits && \strlen($digits) >= 3) {
                // національний префікс 0 (050…, 0412…) у збереженому +380…/+61… не пишеться — відкидаємо один
                $or->add("entity.phone LIKE :ss_p$i");
                $qb->setParameter('ss_p'.$i, '%'.(str_starts_with($digits, '0') ? substr($digits, 1) : $digits).'%');
                if (\strlen($digits) <= self::NUMBER_DIGITS) {
                    $or->add("entity.number LIKE :ss_n$i");
                    $qb->setParameter('ss_n'.$i, '%-'.self::padded($digits));
                }
            }
            $qb->andWhere($or);
        }
    }

    /** Повний номер («2610-000112») або його кінець без нулів («112») — якщо так закінчується рівно одне замовлення. */
    public function exactId(string $query): ?int
    {
        $q = trim($query);
        $qb = $this->em->createQueryBuilder()->select('o.id')->from(Order::class, 'o')->setMaxResults(2);
        if (preg_match('/^\d{4}-\d{'.self::NUMBER_DIGITS.'}$/', $q)) {
            $qb->where('o.number = :n')->setParameter('n', $q);
        } elseif (preg_match('/^\d{1,'.self::NUMBER_DIGITS.'}$/', $q) && 0 !== (int) $q) {
            $qb->where('o.number LIKE :n')->setParameter('n', '%-'.self::padded($q));
        } else {
            return null;
        }
        $ids = $qb->getQuery()->getSingleColumnResult();

        return 1 === \count($ids) ? (int) $ids[0] : null;
    }

    public function hintKey(): string
    {
        return 'search.hint.order';
    }

    private static function padded(string $digits): string
    {
        return str_pad(ltrim($digits, '0'), self::NUMBER_DIGITS, '0', \STR_PAD_LEFT);
    }
}
