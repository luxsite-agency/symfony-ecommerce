<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content;

use App\Core\Content\Entity\Solution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Мови, якими є видиме рішення (опубліковане, з назвою й слагом): пункт меню «Рішення», hreflang і sitemap списку. Один ключ
 * кешу на всі мови. Скидання (forget) — після змін через ORM (SolutionsCacheListener, postFlush) і після сирого SQL
 * (DemoPurger); TTL страхує будь-який інший шлях змін (рев'ю #102: M1, L6).
 */
final class SolutionLocales
{
    public const string CACHE_KEY = 'site_nav.solution_locales';
    public const int TTL = 3600;

    public function __construct(private readonly EntityManagerInterface $em, private readonly CacheInterface $cache)
    {
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL);

            return array_map('strval', $this->em->createQueryBuilder()
                ->select('DISTINCT t.locale')->from(Solution::class, 's')->join('s.translations', 't')
                ->where('s.published = true')->andWhere('t.slug IS NOT NULL')->andWhere("t.title <> ''")
                ->getQuery()->getSingleColumnResult());
        });
    }

    public function forget(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }
}
