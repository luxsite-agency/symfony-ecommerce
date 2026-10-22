<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Routing;

use App\Core\Catalog\Query\CatalogCacheVersion;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Адреса каталогу → id за один lookup (унікальний індекс) з кешем cache.app у versioned namespace CatalogCacheVersion:
 * зміни категорій (слаг, батько) роблять bump, тож шлях категорії в кеші не застаріває. Слаг товару версію не міняє —
 * контролер звіряє слаг знайденого перекладу і при розбіжності шукає ще раз повз кеш (fresh).
 * Кешуються лише знайдені адреси: «нема» з кешу давало б 404 на товар, створений після першого звернення до його адреси
 * (слаг товару версію кешу не міняє). Неіснуючі адреси — один PK-lookup унікальним індексом.
 */
final class SiteUrlResolver
{
    private const int TTL = 3600;
    private const string CATEGORY_SQL = 'SELECT category_id FROM catalog_category_translation WHERE locale = ? AND path = ?';
    private const string PRODUCT_SQL = 'SELECT product_id FROM catalog_product_translation WHERE locale = ? AND slug = ?';

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogCacheVersion $version,
        #[Autowire(service: 'cache.app')] private readonly CacheInterface $cache,
    ) {
    }

    public function category(string $locale, string $path): ?int
    {
        return $this->resolve('c', $locale, $path, self::CATEGORY_SQL);
    }

    public function product(string $locale, string $slug): ?int
    {
        return $this->resolve('p', $locale, $slug, self::PRODUCT_SQL);
    }

    /** Кеш збрехав (слаг змінили, товар видалили) — скинути запис і взяти з БД. */
    public function fresh(string $type, string $locale, string $value): ?int
    {
        $this->cache->delete($this->key($type, $locale, $value));
        $id = (int) $this->db->fetchOne('c' === $type ? self::CATEGORY_SQL : self::PRODUCT_SQL, [$locale, $value]);

        return $id > 0 ? $id : null;
    }

    private function resolve(string $type, string $locale, string $value, string $sql): ?int
    {
        $id = $this->cache->get($this->key($type, $locale, $value), function (ItemInterface $item, bool &$save) use ($sql, $locale, $value): int {
            $item->expiresAfter(self::TTL);
            $id = (int) $this->db->fetchOne($sql, [$locale, $value]);
            $save = $id > 0;

            return $id;
        });

        return $id > 0 ? $id : null;
    }

    private function key(string $type, string $locale, string $value): string
    {
        return 'site.url.'.$this->version->current().'.'.$type.'.'.$locale.'.'.hash('xxh128', $value);
    }
}
