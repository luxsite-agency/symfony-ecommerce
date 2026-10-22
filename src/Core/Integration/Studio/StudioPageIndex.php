<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Integration\Studio;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\ProductTranslation;
use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\Post;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * GET /api/studio/pages — сторінки магазину для перелінковки у зовнішній контент-панелі (StudioApiProvider панелі): товари,
 * категорії, контентні сторінки й записи блогу кожною мовою магазину, лише публічні (те саме, що віддає сайт, а не 404).
 * URL — шляхом без хоста (панель додає адресу сайту), priority — як у панелі: товар 2, категорія 3, запис 6, сторінка 8.
 * Товари читаються скалярами без гідрації сутностей: у каталозі їх можуть бути десятки тисяч.
 *
 * @phpstan-type StudioPage array{url: string, lang: string, type: string, title: string, h1: string, description: ?string, anchors: list<string>, priority: int}
 */
final class StudioPageIndex
{
    private const int DESCRIPTION_MAX = 300;

    /** @param list<string> $locales */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%app.locales%')] private readonly array $locales,
    ) {
    }

    /** @return list<StudioPage> */
    public function pages(): array
    {
        return [...$this->categories(), ...$this->products(), ...$this->contentPages(), ...$this->posts()];
    }

    /** @return list<StudioPage> */
    private function products(): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('t.locale, t.slug, t.name, t.shortDescription, t.metaDescription')
            ->from(ProductTranslation::class, 't')->join('t.product', 'p')
            ->where('p.active = true')->andWhere('t.slug IS NOT NULL')->andWhere("t.name <> ''")->andWhere('t.locale IN (:locales)')
            ->setParameter('locales', $this->locales)
            ->orderBy('p.id')->addOrderBy('t.locale')
            ->getQuery()->toIterable();
        $pages = [];
        foreach ($rows as $row) {
            /** @var array{locale: string, slug: string, name: string, shortDescription: ?string, metaDescription: ?string} $row */
            $pages[] = $this->page('product', $row['locale'], 'site_product', $row['slug'], $row['name'], $row['shortDescription'] ?? $row['metaDescription'], 2);
        }

        return $pages;
    }

    /** @return list<StudioPage> */
    private function categories(): array
    {
        $pages = [];
        /** @var list<Category> $categories */
        $categories = $this->em->getRepository(Category::class)->findBy(['active' => true]);
        foreach ($categories as $category) {
            foreach ($category->getAncestors() as $ancestor) {
                if (!$ancestor->isActive()) {
                    continue 2; // вимкнена гілка ховає піддерево (CategoryPageController::isVisible)
                }
            }
            foreach ($this->locales as $locale) {
                $tr = $category->getTranslation($locale);
                if (null !== $tr?->getPath() && '' !== $tr->getName()) {
                    // URL категорії — повний шлях за деревом (site_category {path}, PR #21)
                    $pages[] = $this->page('category', $locale, 'site_category', $tr->getPath(), $tr->getName(), $tr->getMetaDescription() ?? $tr->getDescription(), 3, 'path');
                }
            }
        }

        return $pages;
    }

    /** @return list<StudioPage> */
    private function contentPages(): array
    {
        $pages = [];
        /** @var list<Page> $items */
        $items = $this->em->getRepository(Page::class)->findBy(['published' => true], ['position' => 'ASC']);
        foreach ($items as $item) {
            foreach ($this->locales as $locale) {
                $tr = $item->getTranslation($locale);
                if (null !== $tr?->getSlug() && '' !== $tr->getTitle()) {
                    $pages[] = $this->page('page', $locale, 'site_page', $tr->getSlug(), $tr->getTitle(), $tr->getMetaDescription(), 8);
                }
            }
        }

        return $pages;
    }

    /** @return list<StudioPage> */
    private function posts(): array
    {
        $pages = [];
        /** @var list<Post> $posts */
        $posts = $this->em->createQueryBuilder()->select('p')->from(Post::class, 'p')
            ->where('p.publishedAt IS NOT NULL')->andWhere('p.publishedAt <= :now')->setParameter('now', new DateTimeImmutable())
            ->orderBy('p.publishedAt', 'DESC')->getQuery()->getResult();
        foreach ($posts as $post) {
            foreach ($this->locales as $locale) {
                $tr = $post->getTranslation($locale);
                if (null !== $tr?->getSlug() && '' !== $tr->getTitle()) {
                    $pages[] = $this->page('post', $locale, 'site_post', $tr->getSlug(), $tr->getTitle(), $tr->getExcerpt() ?? $tr->getMetaDescription(), 6);
                }
            }
        }

        return $pages;
    }

    /** @return StudioPage */
    private function page(string $type, string $locale, string $route, string $slug, string $title, ?string $description, int $priority, string $param = 'slug'): array
    {
        $description = null === $description ? '' : trim((string) preg_replace('/\s+/u', ' ', StudioHtml::text($description)));

        return [
            'url' => $this->urls->generate($route, [$param => $slug, '_locale' => $locale]),
            'lang' => $locale,
            'type' => $type,
            'title' => $title,
            'h1' => $title,
            'description' => '' === $description ? null : mb_substr($description, 0, self::DESCRIPTION_MAX),
            'anchors' => [mb_strtolower($title)],
            'priority' => $priority,
        ];
    }
}
