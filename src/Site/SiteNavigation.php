<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site;

use App\Core\Catalog\Entity\Category;
use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\Post;
use App\Core\Content\SolutionLocales;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/** Дані для шапки/футера й головної: опубліковані сторінки за місцем, кореневі категорії, останні записи, чи є рішення. Без кешу у v1. */
final class SiteNavigation implements ResetInterface
{
    /** @var list<string>|null мови з видимими рішеннями в межах запиту (шапка, мобільне меню й футер питають тричі) */
    private ?array $solutionLocales = null;

    public function __construct(private readonly EntityManagerInterface $em, private readonly SolutionLocales $solutions)
    {
    }

    public function reset(): void
    {
        $this->solutionLocales = null;
    }

    /** @return list<Page> */
    public function pages(string $place): array
    {
        $field = 'header' === $place ? 'p.showInHeader' : 'p.showInFooter';
        /** @var list<Page> $pages */
        // без фільтра за мовою: назва й URL беруться з фолбеком на мову за замовчуванням (Twig tr()/site_url())
        $pages = $this->em->createQueryBuilder()->select('p')->from(Page::class, 'p')
            ->where('p.published = true')->andWhere($field.' = true')
            ->orderBy('p.position', 'ASC')->addOrderBy('p.id', 'ASC')->getQuery()->getResult();

        return $pages;
    }

    /** @return list<Category> */
    public function rootCategories(): array
    {
        /** @var list<Category> $categories */
        $categories = $this->em->createQueryBuilder()->select('c')->from(Category::class, 'c')
            ->where('c.parent IS NULL')->andWhere('c.active = true')
            ->orderBy('c.position', 'ASC')->getQuery()->getResult();

        return $categories;
    }

    /** Є видиме рішення мовою $locale (пункт меню «Рішення»; магазин без рішень виглядає як раніше). Без запиту — SolutionLocales. */
    public function hasSolutions(string $locale): bool
    {
        return \in_array($locale, $this->solutionLocales(), true);
    }

    /** @return list<string> мови з видимими рішеннями (у межах запиту — один раз) */
    public function solutionLocales(): array
    {
        return $this->solutionLocales ??= $this->solutions->all();
    }

    /** @return list<Post> */
    public function latestPosts(int $limit = 6): array
    {
        /** @var list<Post> $posts */
        $posts = $this->em->createQueryBuilder()->select('p')->from(Post::class, 'p')
            ->where('p.publishedAt IS NOT NULL')->andWhere('p.publishedAt <= :now')
            ->setParameter('now', new DateTimeImmutable())
            ->orderBy('p.publishedAt', 'DESC')->setMaxResults($limit)->getQuery()->getResult();

        return $posts;
    }
}
