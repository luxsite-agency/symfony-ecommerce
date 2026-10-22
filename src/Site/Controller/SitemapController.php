<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Controller;

use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\Post;
use App\Core\Content\Entity\Solution;
use App\Site\Seo\CatalogSitemap;
use App\Site\SiteUrlGenerator;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * sitemap.xml: головна, блог, опубліковані сторінки й записи, категорії й товари — кожною мовою з xhtml:link alternate
 * лише для мов, де сторінка є (seo-tech-notes п. 1). Понад app.sitemap_max_urls адрес — sitemap index: /sitemap-main.xml
 * (усе, крім товарів) і /sitemap-products-N.xml пакетами (ліміт протоколу — 50 000 URL на файл).
 */
final class SitemapController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SiteUrlGenerator $siteUrls,
        private readonly CatalogSitemap $catalog,
        #[Autowire('%app.sitemap_max_urls%')] private readonly int $maxUrls = 45000,
    ) {
    }

    public function __invoke(): Response
    {
        $locales = \count($this->siteUrls->locales());
        $plan = $this->catalog->plan($this->productsPerFile());
        // великий каталог — індекс без жодного запиту до сторінок/записів/категорій (план — з кешу)
        if ($plan['count'] * $locales > $this->maxUrls) {
            return $this->index($plan['starts']);
        }
        $main = $this->mainEntries();
        if (self::urls($main) + $plan['count'] * $locales > $this->maxUrls) {
            return $this->index($plan['starts']);
        }

        return $this->urlset([...$main, ...$this->catalog->products(0, max(1, $plan['count']))]);
    }

    /** @param list<int> $starts */
    private function index(array $starts): Response
    {
        $files = [$this->siteUrls->absolute('site_sitemap_main')];
        foreach (array_keys($starts) as $i) {
            $files[] = $this->siteUrls->absolute('site_sitemap_products', ['page' => $i + 1]);
        }
        $response = $this->render('site/sitemap_index.xml.twig', ['files' => $files]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }

    #[Route('/sitemap-main.xml', name: 'site_sitemap_main', methods: ['GET', 'HEAD'], priority: 10)]
    public function main(): Response
    {
        return $this->urlset($this->mainEntries());
    }

    #[Route('/sitemap-products-{page}.xml', name: 'site_sitemap_products', requirements: ['page' => '[1-9]\\d*'], methods: ['GET', 'HEAD'], priority: 10)]
    public function products(int $page): Response
    {
        $start = $this->catalog->plan($this->productsPerFile())['starts'][$page - 1] ?? null;
        $entries = null === $start ? [] : $this->catalog->products($start, $this->productsPerFile());
        if ([] === $entries) {
            throw $this->createNotFoundException();
        }

        return $this->urlset($entries);
    }

    /** @return list<array{alternates: array<string, string>, lastmod: ?DateTimeInterface}> */
    private function mainEntries(): array
    {
        $entries = [];
        $locales = $this->siteUrls->locales();
        $entries[] = ['alternates' => array_combine($locales, array_map($this->siteUrls->home(...), $locales)), 'lastmod' => null];
        $entries[] = ['alternates' => array_combine($locales, array_map($this->siteUrls->blog(...), $locales)), 'lastmod' => null];

        /** @var list<Page> $pages */
        $pages = $this->em->getRepository(Page::class)->findBy(['published' => true], ['position' => 'ASC']);
        foreach ($pages as $page) {
            $alternates = array_filter(array_combine($locales, array_map(fn (string $l): ?string => $this->siteUrls->page($page, $l), $locales)));
            if ([] !== $alternates) {
                $entries[] = ['alternates' => $alternates, 'lastmod' => $page->getUpdatedAt()];
            }
        }
        /** @var list<Post> $posts */
        $posts = $this->em->createQueryBuilder()->select('p')->from(Post::class, 'p')
            ->where('p.publishedAt IS NOT NULL')->andWhere('p.publishedAt <= :now')->setParameter('now', new DateTimeImmutable())
            ->orderBy('p.publishedAt', 'DESC')->getQuery()->getResult();
        foreach ($posts as $post) {
            $alternates = array_filter(array_combine($locales, array_map(fn (string $l): ?string => $this->siteUrls->post($post, $l), $locales)));
            if ([] !== $alternates) {
                $entries[] = ['alternates' => $alternates, 'lastmod' => $post->getUpdatedAt()];
            }
        }

        /** @var list<Solution> $solutions */
        $solutions = $this->em->getRepository(Solution::class)->findBy(['published' => true], ['position' => 'ASC', 'id' => 'ASC']);
        if ([] !== $solutions) {
            // список — лише мовами, де є хоч одне видиме рішення (інакше /…/solutions/ — 404)
            $listed = [];
            foreach ($solutions as $solution) {
                $alternates = array_filter(array_combine($locales, array_map(fn (string $l): ?string => $this->siteUrls->solution($solution, $l), $locales)));
                if ([] !== $alternates) {
                    $entries[] = ['alternates' => $alternates, 'lastmod' => $solution->getUpdatedAt()];
                    $listed += array_fill_keys(array_keys($alternates), true);
                }
            }
            $indexLocales = array_values(array_filter($locales, static fn (string $l): bool => isset($listed[$l])));
            if ([] !== $indexLocales) {
                $entries[] = ['alternates' => array_combine($indexLocales, array_map(fn (string $l): string => $this->siteUrls->absolute('site_solutions', ['_locale' => $l]), $indexLocales)), 'lastmod' => null];
            }
        }

        return [...$entries, ...$this->catalog->categories()];
    }

    /** @param list<array{alternates: array<string, string>, lastmod: ?DateTimeInterface}> $entries */
    private function urlset(array $entries): Response
    {
        $response = $this->render('site/sitemap.xml.twig', ['entries' => $entries]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }

    private function productsPerFile(): int
    {
        return max(1, intdiv($this->maxUrls, max(1, \count($this->siteUrls->locales()))));
    }

    /** @param list<array{alternates: array<string, string>, lastmod: mixed}> $entries */
    private static function urls(array $entries): int
    {
        return array_sum(array_map(static fn (array $e): int => \count($e['alternates']), $entries));
    }
}
