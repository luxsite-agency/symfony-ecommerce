<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Search;

use Doctrine\DBAL\Connection;

/**
 * Збіг товарів за текстом — спільні запити пошуку вітрини (ProductSearch, #98) і розумного пошуку адмінки
 * (App\Admin\Search\ProductAdminSearcher): назва (FULLTEXT ft_catalog_product_translation_name з префіксом слів, короткі —
 * LIKE), SKU за префіксом (унікальний індекс sku), назва бренду (брендів мало — LIKE).
 * Адмінка шукає підрядок (*Containing): «QUOTE» з «RCK-QUOTE», «складськ» з «складської», стоп-слова FULLTEXT («the», «for»).
 * $locale null — усі мови; $activeOnly — лише активні товари (вітрина). Повертає id товарів, не більше $limit.
 */
final class ProductTextMatch
{
    /** innodb_ft_min_token_size: коротші слова FULLTEXT не індексує — для них LIKE. */
    public const int MIN_WORD = 3;
    public const int MAX_WORDS = 6;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<int> */
    public function byName(string $text, ?string $locale, bool $activeOnly, int $limit): array
    {
        $boolean = self::booleanQuery($text);
        $where = null !== $boolean ? 'MATCH(t.name) AGAINST(? IN BOOLEAN MODE)' : 't.name LIKE ?';
        $params = [null !== $boolean ? $boolean : '%'.self::escapeLike($text).'%'];
        if (null !== $locale) {
            $where .= ' AND t.locale = ?';
            $params[] = $locale;
        }

        return $this->ids(
            'SELECT DISTINCT t.product_id FROM catalog_product_translation t'.$this->activeJoin('t.product_id', $activeOnly).' WHERE '.$where.' LIMIT '.$limit,
            $params,
        );
    }

    /**
     * Назва будь-якою мовою містить рядок (LIKE '%…%' без FULLTEXT: будь-яке місце слова, дефіси й стоп-слова як є; без регістру —
     * колація БД). Повний перегляд перекладів — для адмінки (тисячі товарів), не для вітрини.
     *
     * @return list<int>
     */
    public function byNameContaining(string $text, int $limit): array
    {
        return $this->ids('SELECT DISTINCT t.product_id FROM catalog_product_translation t WHERE t.name LIKE ? LIMIT '.$limit, ['%'.self::escapeLike($text).'%']);
    }

    /**
     * SKU або штрихкод варіанта містить рядок (адмінка: «quote» → RCK-QUOTE, «1234» → кінець штрихкоду).
     *
     * @return list<int>
     */
    public function byCodeContaining(string $text, int $limit): array
    {
        $like = '%'.self::escapeLike($text).'%';

        return $this->ids('SELECT DISTINCT v.product_id FROM catalog_product_variant v WHERE v.sku LIKE ? OR v.barcode LIKE ? LIMIT '.$limit, [$like, $like]);
    }

    /** @return list<int> */
    public function bySkuPrefix(string $prefix, bool $activeOnly, int $limit): array
    {
        return $this->ids(
            'SELECT DISTINCT v.product_id FROM catalog_product_variant v'.$this->activeJoin('v.product_id', $activeOnly).' WHERE v.sku LIKE ? LIMIT '.$limit,
            [self::escapeLike($prefix).'%'],
        );
    }

    /** @return list<int> */
    public function byBrand(string $text, bool $activeOnly, int $limit): array
    {
        return $this->ids(
            'SELECT p.id FROM catalog_brand b JOIN catalog_product p ON p.brand_id = b.id'.($activeOnly ? ' AND p.active = 1' : '').' WHERE b.name LIKE ? LIMIT '.$limit,
            ['%'.self::escapeLike($text).'%'],
        );
    }

    /**
     * Товари, у яких SKU або штрихкод варіанта точно дорівнює рядку (без регістру — колація БД).
     *
     * @return list<int>
     */
    public function byExactCode(string $code): array
    {
        return $this->ids('SELECT DISTINCT v.product_id FROM catalog_product_variant v WHERE v.sku = ? OR v.barcode = ? LIMIT 2', [$code, $code]);
    }

    /**
     * «+слово1* +слово2*»: лише літери й цифри (оператори FULLTEXT з URL не проходять; «КША-180» → два слова), слова від
     * MIN_WORD символів, не більше MAX_WORDS; null — таких слів нема (тоді LIKE).
     */
    public static function booleanQuery(string $q): ?string
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $q, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) >= self::MIN_WORD) {
                $words[] = '+'.$word.'*';
            }
        }

        return [] === $words ? null : implode(' ', \array_slice($words, 0, self::MAX_WORDS));
    }

    public static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    private function activeJoin(string $column, bool $activeOnly): string
    {
        return $activeOnly ? ' JOIN catalog_product p ON p.id = '.$column.' AND p.active = 1' : '';
    }

    /**
     * @param list<string> $params
     *
     * @return list<int>
     */
    private function ids(string $sql, array $params): array
    {
        return array_map(intval(...), $this->db->fetchFirstColumn($sql, $params));
    }
}
