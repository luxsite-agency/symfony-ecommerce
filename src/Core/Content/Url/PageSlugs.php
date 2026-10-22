<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content\Url;

use App\Core\Catalog\Url\ReservedPathSegments;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Слаги сторінок (Page), коли вони в корені сайту (app.page_path_prefix: ''): /{slug}/ ділить перший сегмент адреси з категоріями
 * (/{шлях}/), товарами (/{slug}) і службовими маршрутами. У межах мови слаг сторінки не збігається з шляхом кореневої категорії,
 * слагом товару чи зарезервованим словом — той самий список, що в CatalogSlugs (ReservedPathSegments з роутера). З префіксом
 * '/page' перетинів нема — усе дозволено, як було.
 */
final class PageSlugs
{
    public function __construct(
        private readonly Connection $db,
        private readonly ReservedPathSegments $reserved,
        #[Autowire('%app.page_path_prefix%')] private readonly string $prefix,
    ) {
    }

    public function atRoot(): bool
    {
        return '' === $this->prefix;
    }

    /** Ключ перекладу (validators) причини, чому слаг сторінки зайнятий, або null — вільний. */
    public function conflict(string $slug, string $locale): ?string
    {
        if (!$this->atRoot()) {
            return null;
        }

        return match (true) {
            $this->reserved->isReserved($slug) => 'content.page_translation.slug.reserved',
            $this->taken('SELECT 1 FROM catalog_category_translation WHERE locale = ? AND path = ? LIMIT 1', $slug, $locale) => 'content.page_translation.slug.category',
            $this->taken('SELECT 1 FROM catalog_product_translation WHERE locale = ? AND slug = ? LIMIT 1', $slug, $locale) => 'content.page_translation.slug.product',
            default => null,
        };
    }

    /** Для CatalogSlugs: у корені слаг товару чи кореневої категорії не може збігатися зі сторінкою цієї мови. */
    public function pageTaken(string $slug, string $locale): bool
    {
        return $this->atRoot() && $this->taken('SELECT 1 FROM content_page_translation WHERE locale = ? AND slug = ? LIMIT 1', $slug, $locale);
    }

    private function taken(string $sql, string $slug, string $locale): bool
    {
        return false !== $this->db->fetchOne($sql, [$locale, $slug]);
    }
}
