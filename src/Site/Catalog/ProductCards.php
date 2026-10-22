<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Catalog;

use App\Core\Catalog\FilterIndex\FilterIndexTables;
use App\Core\Catalog\Query\CatalogHit;
use App\Core\Catalog\Review\ProductRatings;
use App\Core\Shared\Money\Money;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Картки лістингу для сторінки хітів CatalogQuery: назва/слаг мовою сторінки, бренд, перше зображення, наявність
 * (з read-index, та сама семантика, що й у фільтрі «в наявності»). Три запити на сторінку незалежно від її розміру,
 * порядок — як у хітах. Товар без перекладу цією мовою лишається в списку (лічильники фасетів його рахують), але без
 * посилання: його сторінки цією мовою нема. Зірки — з агрегату схвалених відгуків (ProductRatings, четвертий запит), лише
 * для товарів з відгуками.
 */
final readonly class ProductCards
{
    public const string AVAILABLE = 'in_stock';
    public const string TO_ORDER = 'to_order';
    public const string OUT = 'out_of_stock';

    public function __construct(
        private Connection $db,
        private ProductRatings $ratings,
        #[Autowire('%app.default_currency%')] private string $currency,
    ) {
    }

    /**
     * @param list<CatalogHit> $hits
     *
     * @return list<array{id: int, name: string, slug: ?string, brand: ?string, image: ?string, imageAlt: ?string, price: ?Money, priceFrom: bool, availability: string, rating: ?array{average: float, stars: int, count: int}}>
     */
    public function load(array $hits, string $locale): array
    {
        $ids = array_map(static fn (CatalogHit $h): int => $h->productId, $hits);
        if ([] === $ids) {
            return [];
        }

        $info = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT p.id, (SELECT v.sku FROM catalog_product_variant v WHERE v.product_id = p.id ORDER BY v.position, v.id LIMIT 1) AS sku, t.name, t.slug, b.name AS brand FROM catalog_product p
             LEFT JOIN catalog_product_translation t ON t.product_id = p.id AND t.locale = ?
             LEFT JOIN catalog_brand b ON b.id = p.brand_id
             WHERE p.id IN (?)',
            [$locale, $ids],
            [ParameterType::STRING, ArrayParameterType::INTEGER],
        ) as $r) {
            $info[(int) $r['id']] = $r;
        }

        $images = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT pm.product_id, m.path, m.alt FROM catalog_product_media pm JOIN catalog_media m ON m.id = pm.media_id
             WHERE pm.product_id IN (?) ORDER BY pm.product_id, pm.variant_id IS NOT NULL, pm.position, pm.id',
            [$ids],
            [ArrayParameterType::INTEGER],
        ) as $r) {
            $images[(int) $r['product_id']] ??= ['path' => (string) $r['path'], 'alt' => null !== $r['alt'] ? (string) $r['alt'] : null];
        }

        $stock = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT product_id, MAX(in_stock) AS in_stock, MAX(purchasable) AS purchasable FROM '.FilterIndexTables::VARIANT.'
             WHERE product_id IN (?) AND listed = 1 GROUP BY product_id',
            [$ids],
            [ArrayParameterType::INTEGER],
        ) as $r) {
            $stock[(int) $r['product_id']] = match (true) {
                1 === (int) $r['in_stock'] => self::AVAILABLE,
                1 === (int) $r['purchasable'] => self::TO_ORDER,
                default => self::OUT,
            };
        }

        $ratings = $this->ratings->forProducts($ids);

        $cards = [];
        foreach ($hits as $hit) {
            $row = $info[$hit->productId] ?? null;
            if (null === $row) {
                continue; // товар видалено між запитом індексу і цим — пропустити, не 500
            }
            $cards[] = [
                'id' => $hit->productId,
                'name' => (string) ($row['name'] ?? $row['sku']),
                'slug' => null !== $row['slug'] ? (string) $row['slug'] : null,
                'brand' => null !== $row['brand'] ? (string) $row['brand'] : null,
                'image' => $images[$hit->productId]['path'] ?? null,
                'imageAlt' => $images[$hit->productId]['alt'] ?? null,
                'price' => $hit->isOnRequest() ? null : Money::of($hit->minPriceMinor, $this->currency),
                'priceFrom' => CatalogHit::PRICE_FROM === $hit->priceMode,
                'availability' => $stock[$hit->productId] ?? self::OUT,
                'rating' => isset($ratings[$hit->productId]) ? ['average' => $ratings[$hit->productId]->average(), 'stars' => $ratings[$hit->productId]->stars(), 'count' => $ratings[$hit->productId]->count] : null,
            ];
        }

        return $cards;
    }
}
