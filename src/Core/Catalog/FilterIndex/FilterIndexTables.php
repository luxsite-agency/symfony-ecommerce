<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\FilterIndex;

/**
 * Імена трьох таблиць read-index (ADR Rev 9). live — робочі; shadow — куди пише повна перебудова до атомарного
 * RENAME TABLE; old — куди RENAME відкладає попередні (їх дропають після swap). Усі під префіксом catalog_filter_,
 * тобто поза doctrine schema_filter.
 */
final readonly class FilterIndexTables
{
    public const string VARIANT = 'catalog_filter_variant';
    public const string VALUE = 'catalog_filter_value';
    public const string PRODUCT_CATEGORY = 'catalog_filter_product_category';
    public const string SHADOW_SUFFIX = '__shadow';
    public const string OLD_SUFFIX = '__old';

    private function __construct(public string $variant, public string $value, public string $productCategory, public string $suffix)
    {
    }

    public static function live(): self
    {
        return new self(self::VARIANT, self::VALUE, self::PRODUCT_CATEGORY, '');
    }

    public static function shadow(): self
    {
        return self::withSuffix(self::SHADOW_SUFFIX);
    }

    public static function old(): self
    {
        return self::withSuffix(self::OLD_SUFFIX);
    }

    public function isLive(): bool
    {
        return '' === $this->suffix;
    }

    /** @return list<string> */
    public function all(): array
    {
        return [$this->variant, $this->value, $this->productCategory];
    }

    /** @return list<string> базові імена без суфікса, у тому ж порядку, що й all() */
    public static function baseNames(): array
    {
        return [self::VARIANT, self::VALUE, self::PRODUCT_CATEGORY];
    }

    private static function withSuffix(string $suffix): self
    {
        return new self(self::VARIANT.$suffix, self::VALUE.$suffix, self::PRODUCT_CATEGORY.$suffix, $suffix);
    }
}
