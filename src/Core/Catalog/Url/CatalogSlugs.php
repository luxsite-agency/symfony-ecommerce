<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Url;

use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Content\Url\PageSlugs;
use Doctrine\DBAL\Connection;

/**
 * Єдині правила адрес каталогу (URL без /c/ і /p/): товар — /{slug}, категорія — /{шлях за деревом}/.
 * У межах мови: слаг товару унікальний серед товарів і не збігається з шляхом кореневої категорії (обидва — перший
 * сегмент URL) та із зарезервованими словами; категорія — унікальна серед сестер (повний шлях унікальний), коренева —
 * ще й не збігається зі слагами товарів і зарезервованими словами; зі сторінками в корені (app.page_path_prefix: '') —
 * і зі слагами сторінок (PageSlugs). Генерація — з суфіксом -2, -3… (імпорт, демо);
 * перевірка без суфікса — для валідатора адмінки.
 */
final class CatalogSlugs
{
    /** @var array<string, true> видані в поточному процесі до flush: "type|locale|value" */
    private array $issued = [];

    public function __construct(private readonly Connection $db, private readonly ReservedPathSegments $reserved, private readonly PageSlugs $pages)
    {
    }

    /** Чому слаг товару зайнятий (null — вільний). */
    public function productConflict(string $slug, string $locale, ?int $exceptProductId = null): ?string
    {
        return $this->productProblem($slug, $locale, $exceptProductId)?->getMessage();
    }

    /** Те саме з ключем перекладу error.catalog_slug.* (валідатор форми адмінки); виняток не кидається — це відповідь. */
    public function productProblem(string $slug, string $locale, ?int $exceptProductId = null): ?CatalogInvariantViolation
    {
        return match (true) {
            $this->reserved->isReserved($slug) => new CatalogInvariantViolation(\sprintf('«%s» — службова адреса сайту', $slug), 'error.catalog_slug.reserved', ['slug' => $slug]),
            $this->productTaken($slug, $locale, $exceptProductId) => new CatalogInvariantViolation('Товар з таким слагом уже є цією мовою', 'error.catalog_slug.product_taken'),
            $this->categoryPathTaken($slug, $locale, null) => new CatalogInvariantViolation('Такий слаг має коренева категорія цією мовою (адреси товарів і категорій спільні)', 'error.catalog_slug.root_category_has_slug'),
            $this->pages->pageTaken($slug, $locale) => new CatalogInvariantViolation('Такий слаг має сторінка цією мовою (сторінки в корені сайту)', 'error.catalog_slug.page_has_slug'),
            default => null,
        };
    }

    /** @param string|null $parentPath шлях батька цією мовою; null — коренева категорія */
    public function categoryConflict(string $slug, string $locale, ?string $parentPath, ?int $exceptCategoryId = null): ?string
    {
        return $this->categoryProblem($slug, $locale, $parentPath, $exceptCategoryId)?->getMessage();
    }

    /** @param string|null $parentPath шлях батька цією мовою; null — коренева категорія */
    public function categoryProblem(string $slug, string $locale, ?string $parentPath, ?int $exceptCategoryId = null): ?CatalogInvariantViolation
    {
        $path = null === $parentPath ? $slug : $parentPath.'/'.$slug;

        return match (true) {
            null === $parentPath && $this->reserved->isReserved($slug) => new CatalogInvariantViolation(\sprintf('«%s» — службова адреса сайту', $slug), 'error.catalog_slug.reserved', ['slug' => $slug]),
            $this->categoryPathTaken($path, $locale, $exceptCategoryId) => null === $parentPath
                ? new CatalogInvariantViolation('Коренева категорія з таким слагом уже є цією мовою', 'error.catalog_slug.root_category_taken')
                : new CatalogInvariantViolation('У батьківської категорії вже є підкатегорія з таким слагом', 'error.catalog_slug.subcategory_taken'),
            null === $parentPath && $this->productTaken($slug, $locale, null) => new CatalogInvariantViolation('Такий слаг має товар цією мовою (адреси товарів і категорій спільні)', 'error.catalog_slug.product_has_slug'),
            null === $parentPath && $this->pages->pageTaken($slug, $locale) => new CatalogInvariantViolation('Такий слаг має сторінка цією мовою (сторінки в корені сайту)', 'error.catalog_slug.page_has_slug'),
            default => null,
        };
    }

    public function uniqueProductSlug(string $base, string $locale, ?int $exceptProductId = null): string
    {
        return $this->unique($base, fn (string $s): bool => null === $this->productConflict($s, $locale, $exceptProductId) && !isset($this->issued['p|'.$locale.'|'.$s]) && !isset($this->issued['c|'.$locale.'|'.$s]), 'p|'.$locale);
    }

    public function uniqueCategorySlug(string $base, string $locale, ?string $parentPath, ?int $exceptCategoryId = null): string
    {
        $key = static fn (string $s): string => null === $parentPath ? $s : $parentPath.'/'.$s;

        return $this->unique(
            $base,
            fn (string $s): bool => null === $this->categoryConflict($s, $locale, $parentPath, $exceptCategoryId)
                && !isset($this->issued['c|'.$locale.'|'.$key($s)]) && (null !== $parentPath || !isset($this->issued['p|'.$locale.'|'.$s])),
            'c|'.$locale,
            $key,
        );
    }

    /** Між пакетами імпорту (після flush/clear) видані слаги вже в БД. */
    public function reset(): void
    {
        $this->issued = [];
    }

    /**
     * @param callable(string): bool          $free
     * @param (callable(string): string)|null $issuedKey
     */
    private function unique(string $base, callable $free, string $prefix, ?callable $issuedKey = null): string
    {
        $base = mb_substr(trim($base, '-'), 0, 180) ?: 'item';
        $slug = $base;
        for ($i = 2; $i < 1000; ++$i) {
            if ($free($slug)) {
                $this->issued[$prefix.'|'.(null === $issuedKey ? $slug : $issuedKey($slug))] = true;

                return $slug;
            }
            $slug = $base.'-'.$i;
        }

        return $base.'-'.bin2hex(random_bytes(3));
    }

    private function productTaken(string $slug, string $locale, ?int $exceptProductId): bool
    {
        return false !== $this->db->fetchOne(
            'SELECT 1 FROM catalog_product_translation WHERE locale = ? AND slug = ?'.(null === $exceptProductId ? '' : ' AND product_id <> '.(int) $exceptProductId).' LIMIT 1',
            [$locale, $slug],
        );
    }

    private function categoryPathTaken(string $path, string $locale, ?int $exceptCategoryId): bool
    {
        return false !== $this->db->fetchOne(
            'SELECT 1 FROM catalog_category_translation WHERE locale = ? AND path = ?'.(null === $exceptCategoryId ? '' : ' AND category_id <> '.(int) $exceptCategoryId).' LIMIT 1',
            [$locale, $path],
        );
    }
}
