<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Repository;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductReview;
use App\Core\Catalog\Enum\ReviewStatus;
use App\Core\Customer\Entity\Customer;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Відгуки: схвалені на картці (сторінками, нові зверху), свої в кабінеті (WHERE customer = :me, без «прихованих id»),
 * лічильник нових для дашборда, добовий ліміт покупця.
 *
 * @extends ServiceEntityRepository<ProductReview>
 */
final class ProductReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductReview::class);
    }

    public function findOneByProductAndCustomer(Product $product, Customer $customer): ?ProductReview
    {
        return $this->findOneBy(['product' => $product, 'customer' => $customer]);
    }

    /** @return list<ProductReview> */
    public function findApproved(Product $product, int $page, int $pageSize): array
    {
        /** @var list<ProductReview> $reviews */
        $reviews = $this->createQueryBuilder('r')
            ->addSelect('c')->join('r.customer', 'c')
            ->andWhere('r.product = :p')->setParameter('p', $product)
            ->andWhere('r.status = :s')->setParameter('s', ReviewStatus::Approved)
            ->orderBy('r.createdAt', 'DESC')->addOrderBy('r.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $pageSize)->setMaxResults($pageSize)
            ->getQuery()->getResult();

        return $reviews;
    }

    public function countApproved(Product $product): int
    {
        return $this->count(['product' => $product, 'status' => ReviewStatus::Approved]);
    }

    /**
     * Схвалені справжні (не демо) відгуки для schema.org Review — останні $limit.
     *
     * @return list<ProductReview>
     */
    public function findApprovedReal(Product $product, int $limit): array
    {
        /** @var list<ProductReview> $reviews */
        $reviews = $this->createQueryBuilder('r')
            ->addSelect('c')->join('r.customer', 'c')
            ->andWhere('r.product = :p')->setParameter('p', $product)
            ->andWhere('r.status = :s')->setParameter('s', ReviewStatus::Approved)
            ->andWhere('r.demo = false')
            ->orderBy('r.createdAt', 'DESC')->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        return $reviews;
    }

    /** @return list<ProductReview> відгуки покупця, нові зверху, з товаром одним запитом */
    public function findForCustomer(Customer $customer): array
    {
        /** @var list<ProductReview> $reviews */
        $reviews = $this->createQueryBuilder('r')
            ->addSelect('p')->join('r.product', 'p')
            ->andWhere('r.customer = :c')->setParameter('c', $customer)
            ->orderBy('r.updatedAt', 'DESC')->addOrderBy('r.id', 'DESC')
            ->getQuery()->getResult();

        return $reviews;
    }

    public function findOneForCustomer(Customer $customer, int $id): ?ProductReview
    {
        return $this->findOneBy(['id' => $id, 'customer' => $customer]);
    }

    public function countCreatedSince(Customer $customer, DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('r')->select('COUNT(r.id)')
            ->andWhere('r.customer = :c')->setParameter('c', $customer)
            ->andWhere('r.createdAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    public function countPending(): int
    {
        return $this->count(['status' => ReviewStatus::Pending]);
    }
}
