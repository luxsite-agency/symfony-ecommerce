<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Search;

use App\Core\Catalog\Query\CatalogCacheVersion;
use App\Core\Catalog\Query\CatalogFilter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Пошук товарів за рядком (/search?q=): назва мовою сторінки, SKU варіанта, назва бренду → список id товарів для
 * CatalogFilter::$productIds (далі — звичайний лістинг read-index: фасети, сортування, сторінки).
 *
 * Запити — ProductTextMatch (спільні з пошуком адмінки): назва — FULLTEXT з префіксом кожного слова (короткі — LIKE),
 * SKU — за префіксом, бренд — LIKE по назві. Неактивні товари не беруться. Список обрізаний
 * CatalogFilter::MAX_PRODUCT_IDS, кешується за версією каталогу (той самий bump, що й лістинг).
 */
final class ProductSearch
{
    public const int MIN_LENGTH = 2;
    public const int MAX_LENGTH = 100;
    public const int MIN_WORD = ProductTextMatch::MIN_WORD;
    private const int TTL = 300;

    public function __construct(
        private readonly ProductTextMatch $match,
        private readonly CatalogCacheVersion $version,
        #[Autowire(service: 'cache.app')] private readonly CacheInterface $cache,
    ) {
    }

    /** Нормалізований запит: пробіли згорнуті, обрізано до MAX_LENGTH; '' — шукати нема чого. */
    public static function normalize(string $raw): string
    {
        $q = trim((string) preg_replace('/\s+/u', ' ', $raw));
        if (mb_strlen($q) > self::MAX_LENGTH) {
            $q = mb_substr($q, 0, self::MAX_LENGTH);
        }

        return mb_strlen($q) < self::MIN_LENGTH ? '' : $q;
    }

    /** @return list<int> id товарів (відсортовані, без дублів); [] — нічого не знайдено або запит закороткий */
    public function ids(string $q, string $locale): array
    {
        $q = self::normalize($q);
        if ('' === $q) {
            return [];
        }
        $key = 'catalog.search.'.$this->version->current().'.'.hash('xxh128', $locale."\0".mb_strtolower($q));

        return $this->cache->get($key, function (ItemInterface $item) use ($q, $locale): array {
            $item->expiresAfter(self::TTL);

            return $this->query($q, $locale);
        });
    }

    /** @return list<int> */
    private function query(string $q, string $locale): array
    {
        $limit = CatalogFilter::MAX_PRODUCT_IDS;
        $ids = array_fill_keys([
            ...$this->match->byName($q, $locale, true, $limit),
            ...$this->match->bySkuPrefix($q, true, $limit),
            ...$this->match->byBrand($q, true, $limit),
        ], true);
        $list = array_keys($ids);
        sort($list);

        return \array_slice($list, 0, $limit);
    }

    /** «+слово1* +слово2*» для FULLTEXT; null — коротких слів замало (ProductTextMatch::booleanQuery). */
    public static function booleanQuery(string $q): ?string
    {
        return ProductTextMatch::booleanQuery($q);
    }
}
