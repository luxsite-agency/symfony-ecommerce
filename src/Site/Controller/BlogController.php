<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Core\Content\Entity\Post;
use App\Core\Content\Entity\PostTranslation;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Блог: список опублікованих записів мовою запиту (пагінація ?page=) і запис за слагом. */
final class BlogController extends AbstractController
{
    private const int PER_PAGE = 12;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function index(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        // список — усі опубліковані; назва/URL з фолбеком на мову за замовчуванням (Twig tr()/site_url())
        $qb = $this->em->createQueryBuilder()->select('p')->from(Post::class, 'p')
            ->where('p.publishedAt IS NOT NULL')->andWhere('p.publishedAt <= :now')
            ->setParameter('now', new DateTimeImmutable())
            ->orderBy('p.publishedAt', 'DESC');
        $total = (int) (clone $qb)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
        /** @var list<Post> $posts */
        $posts = $qb->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE)->getQuery()->getResult();
        if ($page > 1 && [] === $posts) {
            throw $this->createNotFoundException();
        }

        return $this->render('site/blog/index.html.twig', ['posts' => $posts, 'page' => $page, 'pages' => (int) max(1, ceil($total / self::PER_PAGE))]);
    }

    public function show(string $slug, Request $request): Response
    {
        $translation = $this->em->getRepository(PostTranslation::class)->findOneBy(['locale' => $request->getLocale(), 'slug' => $slug]);
        $post = $translation?->getPost();
        if (null === $translation || !$translation->isComplete() || !$post instanceof Post || !$post->isPublished()) {
            // без повного перекладу (заголовок + текст) запис цією мовою не існує — 404, як і в hreflang/sitemap
            throw $this->createNotFoundException('Запис не знайдено');
        }

        return $this->render('site/blog/show.html.twig', ['post' => $post, 'tr' => $translation]);
    }
}
