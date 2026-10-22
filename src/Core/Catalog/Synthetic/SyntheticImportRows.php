<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Synthetic;

use App\Core\Catalog\Enum\AttributeType;
use App\Core\Catalog\Import\ProductImportRow;
use App\Core\Catalog\Import\VariantImportRow;

/**
 * Той самий синтетичний каталог рядками імпорту (джерело synthetic) — щоб ганяти повний конвеєр ProductImporter
 * (staging, ORM-applier, Messenger-reindex) на тих самих даних, що й SQL-режим. Довідники імпорт створює сам
 * (характеристики — не filterable, як будь-які нові з фіду).
 */
final class SyntheticImportRows
{
    private SyntheticNames $names;

    /** @param list<string> $locales */
    public function __construct(private readonly array $locales, private readonly string $defaultLocale)
    {
        $this->names = new SyntheticNames($defaultLocale);
    }

    /** @return iterable<ProductImportRow> */
    public function rows(SyntheticCatalogGenerator $generator): iterable
    {
        $categories = [];
        foreach ($generator->categories() as $category) {
            $categories[$category->index] = $category;
        }
        $attributes = [];
        foreach ($generator->attributes() as $attribute) {
            $attributes[$attribute->index] = $attribute;
        }
        $axis = $generator->axis();

        foreach ($generator->products() as $p) {
            $names = [];
            foreach ($this->locales as $locale) {
                $names[$locale] = $this->names->product($p->number, $locale);
            }
            $paths = [];
            foreach ($p->categories as $leaf) {
                $path = [];
                for ($c = $categories[$leaf]; null !== $c; $c = null === $c->parent ? null : $categories[$c->parent]) {
                    array_unshift($path, $this->names->category($c, $this->defaultLocale));
                }
                $paths[] = $path;
            }
            $values = [];
            foreach ($p->attributes as $index => $value) {
                $attribute = $attributes[$index];
                $values[$attribute->code] = match ($attribute->type) {
                    AttributeType::Select => SyntheticAttribute::valueCode((int) $value),
                    AttributeType::MultiSelect => array_map(SyntheticAttribute::valueCode(...), (array) $value),
                    AttributeType::Number => (string) $value,
                    AttributeType::Boolean => true === $value,
                };
            }
            $variants = [];
            foreach ($p->variants as $position => $v) {
                $variants[] = new VariantImportRow(
                    externalId: SyntheticNames::externalId($p->number, $position),
                    sku: SyntheticNames::sku($p->number, $position),
                    priceMinor: $v->priceMinor,
                    currency: 'UAH',
                    stock: (string) $v->stock,
                    oldPriceMinor: $v->oldPriceMinor,
                    axes: null !== $axis && null !== $v->axisValue ? [$axis->code => SyntheticAttribute::valueCode($v->axisValue)] : [],
                    stockPolicy: $v->policy,
                );
            }

            yield new ProductImportRow(
                externalId: SyntheticNames::externalId($p->number),
                names: $names,
                variants: $variants,
                brand: null === $p->brand ? null : SyntheticNames::brand($p->brand),
                categoryPaths: $paths,
                attributes: $values,
                priceMode: $p->priceMode,
                active: $p->active,
            );
        }
    }
}
