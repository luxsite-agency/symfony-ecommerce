<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Pricing\Promotion;

use App\Core\Pricing\Promotion\Entity\Promotion;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Чинні акції з БД одним запитом із цілями (fetch-join), запам'ятовується на час запиту: кошик на 20 рядків не робить
 * 20 запитів. Акцій — одиниці-десятки, тому весь набір у пам'яті дешевший за індексовану вибірку на кожен варіант.
 * customerGroups теж у fetch-join: інакше allowsGroup робив би запит на акцію, а після $em->clear() (фід, прайс-лист
 * групи в Messenger) відв'язана акція кидала б LazyLoadingException на ініціалізації колекції.
 */
#[AsAlias(PromotionProviderInterface::class)]
final class DoctrinePromotionProvider implements PromotionProviderInterface
{
    /** @var list<Promotion>|null */
    private ?array $cache = null;
    private ?string $cacheMinute = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function running(DateTimeImmutable $at): array
    {
        $minute = $at->format('YmdHi');
        if (null === $this->cache || $this->cacheMinute !== $minute) {
            /** @var list<Promotion> $promotions */
            $promotions = $this->em->createQueryBuilder()
                ->select('p', 'c', 'b', 'pr', 'g')
                ->from(Promotion::class, 'p')
                ->leftJoin('p.categories', 'c')->leftJoin('p.brands', 'b')->leftJoin('p.products', 'pr')->leftJoin('p.customerGroups', 'g')
                ->where('p.active = true')
                ->andWhere('p.startsAt IS NULL OR p.startsAt <= :at')
                ->andWhere('p.endsAt IS NULL OR p.endsAt >= :at')
                ->setParameter('at', $at)
                ->orderBy('p.priority', 'DESC')->addOrderBy('p.id', 'ASC')
                ->getQuery()->getResult();
            $this->cache = $promotions;
            $this->cacheMinute = $minute;
        }

        return $this->cache;
    }

    /** Після змін акцій у тому ж процесі (адмінка, тести). */
    public function reset(): void
    {
        $this->cache = null;
    }
}
