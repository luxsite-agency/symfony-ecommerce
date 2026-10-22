<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Seo;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Query\CatalogCacheVersion;
use App\Site\SiteUrlGenerator;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Каталог у sitemap (seo-tech-notes п. 1): категорії й товари, у кожного — лише мови, де є адреса (переклад зі слагом;
 * для категорії — шлях за деревом), тож hreflang не вказує на неіснуючі сторінки. Категорія — якщо вона й усі предки
 * активні (як CategoryPageController), товар — якщо активний. Товари — DBAL пакетами за id (keyset), без ORM.
 *
 * @phpstan-type Entry array{alternates: array<string, string>, lastmod: ?DateTimeImmutable}
 */
final class CatalogSitemap
{
    private const int TTL = 3600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly SiteUrlGenerator $urls,
        private readonly CatalogCacheVersion $version,
        #[Autowire(service: 'cache.app')] private readonly CacheInterface $cache,
    ) {
    }

    /** @return list<Entry> */
    public function categories(): array
    {
        /** @var list<Category> $categories */
        $categories = $this->em->createQueryBuilder()->select('c', 't')->from(Category::class, 'c')->leftJoin('c.translations', 't')
            ->orderBy('c.id')->getQuery()->getResult();
        $entries = [];
        foreach ($categories as $category) {
            if (!self::visible($category)) {
                continue;
            }
            $alternates = [];
            foreach ($this->urls->locales() as $locale) {
                $url = $this->urls->category($category, $locale);
                if (null !== $url) {
                    $alternates[$locale] = $url;
                }
            }
            if ([] !== $alternates) {
                $entries[] = ['alternates' => $alternates, 'lastmod' => null];
            }
        }

        return $entries;
    }

    /**
     * Розбивка товарів на файли sitemap: скільки активних і з якого id починається кожен файл (по $perFile товарів).
     * Межі — стрибками за первинним ключем (кожен запит проходить один файл), разом один прохід по активних; результат
     * у кеші до зміни каталогу (CatalogCacheVersion) або TTL — /sitemap.xml не рахує 500k на кожен запит.
     *
     * @return array{count: int, starts: list<int>}
     */
    public function plan(int $perFile): array
    {
        return $this->cache->get('site.sitemap.'.$this->version->current().'.'.$perFile, function (ItemInterface $item) use ($perFile): array {
            $item->expiresAfter(self::TTL);
            $starts = [];
            $count = 0;
            $from = (int) $this->db->fetchOne('SELECT MIN(id) FROM catalog_product WHERE active = 1');
            while ($from > 0) {
                $starts[] = $from;
                $ids = $this->db->fetchFirstColumn('SELECT id FROM catalog_product WHERE active = 1 AND id >= ? ORDER BY id LIMIT ?', [$from, $perFile + 1], [ParameterType::INTEGER, ParameterType::INTEGER]);
                $count += min(\count($ids), $perFile);
                $from = \count($ids) > $perFile ? (int) $ids[$perFile] : 0;
            }

            return ['count' => $count, 'starts' => $starts];
        });
    }

    /**
     * Активні товари з id ≥ $fromId (keyset за первинним ключем, без OFFSET), не більше $limit.
     *
     * @return list<Entry>
     */
    public function products(int $fromId, int $limit): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->db->fetchFirstColumn('SELECT id FROM catalog_product WHERE active = 1 AND id >= ? ORDER BY id LIMIT ?', [$fromId, $limit], [ParameterType::INTEGER, ParameterType::INTEGER]);
        if ([] === $ids) {
            return [];
        }
        $byProduct = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT p.id, p.updated_at, t.locale, t.slug FROM catalog_product p
             JOIN catalog_product_translation t ON t.product_id = p.id AND t.slug IS NOT NULL AND t.locale IN (?)
             WHERE p.id IN (?) ORDER BY p.id',
            [$this->urls->locales(), array_map(intval(...), $ids)],
            [ArrayParameterType::STRING, ArrayParameterType::INTEGER],
        ) as $row) {
            $id = (int) $row['id'];
            $byProduct[$id]['lastmod'] = new DateTimeImmutable((string) $row['updated_at']);
            $byProduct[$id]['alternates'][(string) $row['locale']] = $this->urls->absolute('site_product', ['slug' => (string) $row['slug'], '_locale' => (string) $row['locale']]);
        }
        $entries = [];
        foreach ($byProduct as $entry) {
            // мови — у порядку app.locales (стабільний вивід)
            $ordered = array_filter(array_map(static fn (string $l): ?string => $entry['alternates'][$l] ?? null, array_combine($this->urls->locales(), $this->urls->locales())));
            $entries[] = ['alternates' => $ordered, 'lastmod' => $entry['lastmod']];
        }

        return $entries;
    }

    private static function visible(Category $category): bool
    {
        foreach ([...$category->getAncestors(), $category] as $node) {
            if (!$node->isActive()) {
                return false;
            }
        }

        return true;
    }
}
