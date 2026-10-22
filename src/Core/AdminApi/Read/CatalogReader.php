<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Read;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\CategoryTranslation;
use App\Core\Catalog\Entity\PriceTier;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductTranslation;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\PageTranslation;
use App\Core\Content\Entity\Post;
use App\Core\Content\Entity\PostTranslation;
use App\Site\SiteUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Читання каталогу й контенту для REST (E15 PR-4) і інструментів MCP (PR-5): масиви з усіма полями, які можна змінити
 * пакетом (ті самі ключі, що в set_translation/set_variant_price — щоб клієнт склав expect без здогадок), плюс URL на
 * сайті. Жодних замовлень, покупців, залишків (ADR E15 §6): полів stock тут нема навмисно. HTML описів — як у БД
 * (санітизований адмінкою); перетворення на текст і обгортка untrusted — справа транспорту MCP.
 */
final class CatalogReader
{
    public const int MAX_LIMIT = 50;

    public function __construct(private readonly EntityManagerInterface $em, private readonly SiteUrlGenerator $urls)
    {
    }

    /**
     * Пошук товарів: рядок — за SKU варіанта (префікс) або назвою перекладу (підрядок), необов'язково в категорії.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function searchProducts(?string $q, ?int $categoryId, int $limit, int $page): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $page = max(1, $page);
        $qb = $this->em->createQueryBuilder()->select('DISTINCT p.id')->from(Product::class, 'p');
        $q = trim((string) $q);
        if ('' !== $q) {
            $qb->leftJoin('p.variants', 'v')->leftJoin('p.translations', 't')
                ->andWhere('v.sku LIKE :sku OR t.name LIKE :name')
                ->setParameter('sku', self::escapeLike($q).'%')->setParameter('name', '%'.self::escapeLike($q).'%');
        }
        if (null !== $categoryId) {
            $qb->innerJoin('p.categories', 'c')->andWhere('c.id = :category')->setParameter('category', $categoryId);
        }
        $count = (clone $qb)->select('COUNT(DISTINCT p.id)')->resetDQLPart('orderBy');
        $total = (int) $count->getQuery()->getSingleScalarResult();
        /** @var list<array{id: int}> $rows */
        $rows = $qb->orderBy('p.id', 'ASC')->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getScalarResult();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $items = [];
        if ([] !== $ids) {
            /** @var list<Product> $products */
            $products = $this->em->createQueryBuilder()->select('p')->from(Product::class, 'p')->where('p.id IN (:ids)')->setParameter('ids', $ids)->orderBy('p.id', 'ASC')->getQuery()->getResult();
            foreach ($products as $product) {
                $items[] = $this->productSummary($product);
            }
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /**
     * Товар за id або SKU будь-якого варіанта; null — нема.
     *
     * @return array<string, mixed>|null
     */
    public function product(int|string $ref): ?array
    {
        $product = $this->findProduct($ref);

        return null === $product ? null : $this->productFull($product);
    }

    /** @return list<array<string, mixed>> усі категорії деревом (батько перед дітьми) */
    public function categories(): array
    {
        /** @var list<Category> $all */
        $all = $this->em->getRepository(Category::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
        $byParent = [];
        foreach ($all as $category) {
            $byParent[$category->getParent()?->getId() ?? 0][] = $category;
        }
        $out = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$parentId] ?? [] as $category) {
                $out[] = $this->category($category, $depth);
                $walk((int) $category->getId(), $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function page(int|string $ref): ?array
    {
        $page = \is_int($ref) ? $this->em->find(Page::class, $ref) : $this->findBySlug(Page::class, PageTranslation::class, 'page', $ref);

        return $page instanceof Page ? $this->pageArray($page, true) : null;
    }

    /** @return array<string, mixed>|null */
    public function post(int|string $ref): ?array
    {
        $post = \is_int($ref) ? $this->em->find(Post::class, $ref) : $this->findBySlug(Post::class, PostTranslation::class, 'post', $ref);

        return $post instanceof Post ? $this->postArray($post, true) : null;
    }

    /** @return list<array<string, mixed>> */
    public function pages(): array
    {
        /** @var list<Page> $pages */
        $pages = $this->em->getRepository(Page::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']);

        return array_map(fn (Page $p): array => $this->pageArray($p, false), $pages);
    }

    /** @return list<array<string, mixed>> */
    public function posts(int $limit, int $page): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        /** @var list<Post> $posts */
        $posts = $this->em->getRepository(Post::class)->findBy([], ['id' => 'DESC'], $limit, (max(1, $page) - 1) * $limit);

        return array_map(fn (Post $p): array => $this->postArray($p, false), $posts);
    }

    /** Числовий рядок — id, інакше SKU/slug. */
    public static function ref(string $value): int|string
    {
        return 1 === preg_match('/^\d{1,10}$/', $value) ? (int) $value : $value;
    }

    private function findProduct(int|string $ref): ?Product
    {
        if (\is_int($ref)) {
            return $this->em->find(Product::class, $ref);
        }
        $variant = $this->em->getRepository(ProductVariant::class)->findOneBy(['sku' => $ref]);

        return $variant?->getProduct();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param class-string    $translationClass
     *
     * @return T|null
     */
    private function findBySlug(string $class, string $translationClass, string $owner, string $slug): ?object
    {
        /** @var T|null */
        return $this->em->createQueryBuilder()->select('e')->from($class, 'e')
            ->innerJoin($translationClass, 't', 'WITH', \sprintf('t.%s = e AND t.slug = :slug', $owner))
            ->setParameter('slug', $slug)->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** @return array<string, mixed> */
    private function productSummary(Product $product): array
    {
        $default = $product->getDefaultVariant();
        $names = [];
        foreach ($product->getTranslatedLocales() as $locale) {
            $t = $product->getTranslation($locale);
            if ($t instanceof ProductTranslation) {
                $names[$locale] = $t->getName();
            }
        }

        return [
            'id' => $product->getId(),
            'sku' => $default->getSku(),
            'name' => $names,
            'active' => $product->isActive(),
            'featured' => $product->isFeatured(),
            'price' => $default->getPrice()->toDecimalString(),
            'old_price' => $default->getOldPrice()?->toDecimalString(),
            'currency' => $product->getCurrency(),
            'variants' => $product->getVariants()->count(),
            'categories' => array_map(static fn (Category $c): int => (int) $c->getId(), $product->getCategories()->toArray()),
            'main_category' => $product->getMainCategory()?->getId(),
            'url' => $this->urls->product($product, null, false),
        ];
    }

    /** @return array<string, mixed> */
    private function productFull(Product $product): array
    {
        $translations = [];
        foreach ($product->getTranslatedLocales() as $locale) {
            $t = $product->getTranslation($locale);
            if (!$t instanceof ProductTranslation) {
                continue;
            }
            $translations[$locale] = [
                'name' => $t->getName(),
                'slug' => $t->getSlug(),
                'short_description' => $t->getShortDescription(),
                'description' => $t->getDescription(),
                'meta_title' => $t->getMetaTitle(),
                'meta_description' => $t->getMetaDescription(),
                'url' => $this->urls->product($product, $locale, false),
            ];
        }
        $variants = [];
        foreach ($product->getVariants() as $variant) {
            $variants[] = [
                'id' => $variant->getId(),
                'sku' => $variant->getSku(),
                'price' => $variant->getPrice()->toDecimalString(),
                'old_price' => $variant->getOldPrice()?->toDecimalString(),
                'currency' => $variant->getCurrency(),
                'active' => $variant->isActive(),
                'price_tiers' => array_map(static fn (PriceTier $tier): array => [
                    'min_quantity' => $tier->getMinQuantity(),
                    'customer_group' => $tier->getCustomerGroup()?->getCode(),
                    'price' => $tier->getPrice()->toDecimalString(),
                ], $variant->getPriceTiers()->toArray()),
            ];
        }
        $brand = $product->getBrand();

        return $this->productSummary($product) + [
            'kind' => $product->getKind()->value,
            'brand' => null === $brand ? null : ['id' => $brand->getId(), 'name' => $brand->getName()],
            'category_names' => array_map(fn (Category $c): array => ['id' => $c->getId(), 'name' => $this->categoryName($c)], $product->getCategories()->toArray()),
            'translations' => $translations,
            'variant_list' => $variants,
            'updated_at' => $product->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function category(Category $category, int $depth): array
    {
        $translations = [];
        foreach ($category->getTranslatedLocales() as $locale) {
            $t = $category->getTranslation($locale);
            if (!$t instanceof CategoryTranslation) {
                continue;
            }
            $translations[$locale] = [
                'name' => $t->getName(),
                'slug' => $t->getSlug(),
                'path' => $t->getPath(),
                'h1' => $t->getH1(),
                'description' => $t->getDescription(),
                'meta_title' => $t->getMetaTitle(),
                'meta_description' => $t->getMetaDescription(),
                'url' => $this->urls->category($category, $locale, false),
            ];
        }

        return [
            'id' => $category->getId(),
            'parent_id' => $category->getParent()?->getId(),
            'depth' => $depth,
            'position' => $category->getPosition(),
            'active' => $category->isActive(),
            'name' => $this->categoryName($category),
            'translations' => $translations,
        ];
    }

    private function categoryName(Category $category): ?string
    {
        $t = $category->getTranslation($this->urls->defaultLocale()) ?? $category->getTranslation($category->getTranslatedLocales()[0] ?? '');

        return $t instanceof CategoryTranslation ? $t->getName() : null;
    }

    /** @return array<string, mixed> */
    private function pageArray(Page $page, bool $full): array
    {
        $translations = [];
        foreach ($page->getTranslatedLocales() as $locale) {
            $t = $page->getTranslation($locale);
            if (!$t instanceof PageTranslation) {
                continue;
            }
            $translations[$locale] = ['title' => $t->getTitle(), 'slug' => $t->getSlug(), 'url' => $this->urls->page($page, $locale, false)]
                + ($full ? ['body' => $t->getBody(), 'meta_title' => $t->getMetaTitle(), 'meta_description' => $t->getMetaDescription()] : []);
        }

        return ['id' => $page->getId(), 'published' => $page->isPublished(), 'position' => $page->getPosition(), 'translations' => $translations, 'updated_at' => $page->getUpdatedAt()->format(\DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    private function postArray(Post $post, bool $full): array
    {
        $translations = [];
        foreach ($post->getTranslatedLocales() as $locale) {
            $t = $post->getTranslation($locale);
            if (!$t instanceof PostTranslation) {
                continue;
            }
            $translations[$locale] = ['title' => $t->getTitle(), 'slug' => $t->getSlug(), 'url' => $this->urls->post($post, $locale, false)]
                + ($full ? ['excerpt' => $t->getExcerpt(), 'body' => $t->getBody(), 'meta_title' => $t->getMetaTitle(), 'meta_description' => $t->getMetaDescription()] : []);
        }

        return [
            'id' => $post->getId(),
            'published' => $post->isPublished(),
            'published_at' => $post->getPublishedAt()?->format(\DATE_ATOM),
            'category_id' => $post->getCategory()?->getId(),
            'author_name' => $post->getAuthorName(),
            'tags' => $post->getTagList(),
            'translations' => $translations,
            'updated_at' => $post->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
