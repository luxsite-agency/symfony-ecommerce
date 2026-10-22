<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Synthetic;

use App\Core\Catalog\Enum\AttributeType;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Catalog\Query\CatalogCacheVersion;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Масовий запис синтетичного каталогу прямо в OLTP-таблиці (для 500k SKU): багаторядкові INSERT пакетами по N карток,
 * кожен пакет — окрема транзакція, id призначаються явно (1…), жодних ORM-об'єктів — пам'ять не росте з розміром.
 * FK/UNIQUE-перевірки сесії вимкнено на час запису: генератор сам гарантує цілісність. Read-index фільтрів не
 * будується — після запису catalog:filter-index:rebuild (або команда фікстур без --skip-index).
 *
 * Працює лише на порожньому каталозі (або після purge()) — тоді id і слаги не перетинаються з наявними.
 */
final class SyntheticSqlWriter
{
    /** Таблиці каталогу в порядку «діти → батьки»: purge() чистить їх TRUNCATE-ом (DDL — неявний COMMIT). */
    public const array CATALOG_TABLES = [
        'catalog_filter_variant', 'catalog_filter_value', 'catalog_filter_product_category', 'catalog_filter_dirty',
        'catalog_category_filter_attribute', 'catalog_product_variant_axis_value', 'catalog_price_tier', 'catalog_product_media',
        'catalog_option_value_translation', 'catalog_option_value', 'catalog_option_group_translation', 'catalog_option_group',
        'catalog_product_attribute_value', 'catalog_product_category', 'catalog_product_translation', 'catalog_product_variant',
        'catalog_product', 'catalog_attribute_value_translation', 'catalog_attribute_value', 'catalog_attribute_translation',
        'catalog_attribute', 'catalog_category_translation', 'catalog_category', 'catalog_brand', 'catalog_import_staging',
        'catalog_import_run',
    ];
    private const string BASE_TIME = '2025-09-01 00:00:00';

    private SyntheticNames $names;
    /** @var array<string, int> наступний id по таблицях */
    private array $nextId = [];

    /** @param list<string> $locales */
    public function __construct(
        private readonly Connection $db,
        private readonly CatalogCacheVersion $cacheVersion,
        #[Autowire('%app.locales%')] private readonly array $locales,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
    ) {
        $this->names = new SyntheticNames($defaultLocale);
    }

    /** @throws RuntimeException каталог не порожній */
    public function assertEmpty(): void
    {
        foreach (['catalog_product', 'catalog_category', 'catalog_attribute', 'catalog_brand'] as $table) {
            if (false !== $this->db->fetchOne('SELECT 1 FROM '.$table.' LIMIT 1')) {
                throw new RuntimeException(\sprintf('Таблиця %s не порожня: SQL-режим пише в порожній каталог (--purge, лише dev/test)', $table));
            }
        }
    }

    /** Повне очищення каталогу (TRUNCATE) — лише для dev/test, перевіряє викликач. */
    public function purge(): void
    {
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (self::CATALOG_TABLES as $table) {
                $this->db->executeStatement('TRUNCATE TABLE '.$table);
            }
            $this->db->executeStatement("DELETE FROM integration_external_ref WHERE entity_type = 'product'");
        } finally {
            $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * @param callable(int): void|null $progress викликається після кожного пакета з кількістю записаних карток
     *
     * @return array<string, int> кількість рядків по сутностях
     */
    public function write(SyntheticCatalogGenerator $generator, int $batchSize = 2000, ?callable $progress = null): array
    {
        $this->assertEmpty();
        $this->nextId = [];
        $counts = ['categories' => 0, 'attributes' => 0, 'attribute_values' => 0, 'brands' => 0, 'products' => 0, 'variants' => 0,
            'product_translations' => 0, 'product_categories' => 0, 'attribute_rows' => 0, 'axis_rows' => 0];
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 0, UNIQUE_CHECKS = 0');
        try {
            $valueIds = $this->writeDictionaries($generator, $counts);
            $attributes = [];
            foreach ($generator->attributes() as $attribute) {
                $attributes[$attribute->index] = $attribute;
            }
            $axis = $generator->axis();

            $batch = [];
            foreach ($generator->products() as $product) {
                $batch[] = $product;
                if (\count($batch) >= max(1, $batchSize)) {
                    $this->writeProducts($batch, $attributes, $axis, $valueIds, $counts);
                    $batch = [];
                    if (null !== $progress) {
                        $progress($counts['products']);
                    }
                }
            }
            if ([] !== $batch) {
                $this->writeProducts($batch, $attributes, $axis, $valueIds, $counts);
                if (null !== $progress) {
                    $progress($counts['products']);
                }
            }
        } finally {
            $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 1, UNIQUE_CHECKS = 1');
        }
        $this->cacheVersion->bump();

        return $counts;
    }

    /**
     * Категорії, характеристики зі значеннями, бренди — з перекладами всіма мовами.
     *
     * @param array<string, int> $counts
     *
     * @return array<int, array<int, int>> індекс характеристики → індекс значення → id значення
     */
    private function writeDictionaries(SyntheticCatalogGenerator $generator, array &$counts): array
    {
        $categories = $translations = [];
        foreach ($generator->categories() as $category) {
            $categories[] = [$category->index, $category->position, 1, $category->parent];
            foreach ($this->locales as $locale) {
                $translations[] = [$this->id('catalog_category_translation'), $locale, $this->names->category($category, $locale), $this->names->categorySlug($category, $locale), $category->index];
            }
        }
        $this->transaction(function () use ($categories, $translations): void {
            $this->insert('catalog_category', ['id', 'position', 'active', 'parent_id'], $categories);
            $this->insert('catalog_category_translation', ['id', 'locale', 'name', 'slug', 'category_id'], $translations);
        });
        $counts['categories'] = \count($categories);

        $attributeRows = $attributeTranslations = $valueRows = $valueTranslations = [];
        $valueIds = [];
        foreach ($generator->attributes() as $attribute) {
            $attributeRows[] = [$attribute->index, $attribute->code, $attribute->type->value, $attribute->unit, 1, $attribute->axis ? 1 : 0, $attribute->index];
            foreach ($this->locales as $locale) {
                $attributeTranslations[] = [$this->id('catalog_attribute_translation'), $locale, $this->names->attribute($attribute, $locale), $attribute->index];
            }
            for ($v = 1; $v <= $attribute->valueCount; ++$v) {
                $valueId = $this->id('catalog_attribute_value');
                $valueIds[$attribute->index][$v] = $valueId;
                $valueRows[] = [$valueId, SyntheticAttribute::valueCode($v), $v, $attribute->index];
                foreach ($this->locales as $locale) {
                    $valueTranslations[] = [$this->id('catalog_attribute_value_translation'), $locale, $this->names->value($attribute, $v, $locale), $valueId];
                }
            }
        }
        $brands = [];
        for ($b = 1; $b <= $generator->spec->brands; ++$b) {
            $brands[] = [$b, SyntheticNames::brand($b), SyntheticNames::brandSlug($b), 1];
        }
        $this->transaction(function () use ($attributeRows, $attributeTranslations, $valueRows, $valueTranslations, $brands): void {
            $this->insert('catalog_attribute', ['id', 'code', 'type', 'unit', 'filterable', 'variant_axis', 'position'], $attributeRows);
            $this->insert('catalog_attribute_translation', ['id', 'locale', 'name', 'attribute_id'], $attributeTranslations);
            $this->insert('catalog_attribute_value', ['id', 'code', 'position', 'attribute_id'], $valueRows);
            $this->insert('catalog_attribute_value_translation', ['id', 'locale', 'value', 'attribute_value_id'], $valueTranslations);
            $this->insert('catalog_brand', ['id', 'name', 'slug', 'active'], $brands);
        });
        $counts['attributes'] = \count($attributeRows);
        $counts['attribute_values'] = \count($valueRows);
        $counts['brands'] = \count($brands);

        return $valueIds;
    }

    /**
     * @param list<SyntheticProduct>         $batch
     * @param array<int, SyntheticAttribute> $attributes
     * @param array<int, array<int, int>>    $valueIds
     * @param array<string, int>             $counts
     */
    private function writeProducts(array $batch, array $attributes, ?SyntheticAttribute $axis, array $valueIds, array &$counts): void
    {
        $base = strtotime(self::BASE_TIME.' UTC');
        $products = $translations = $categories = $variants = $values = $axisRows = [];
        foreach ($batch as $p) {
            $id = $p->number;
            $at = gmdate('Y-m-d H:i:s', $base + $id * 60);
            $products[] = [$id, 'goods', $p->priceMode->value, $p->active ? 1 : 0, $at, $at, $p->brand, $p->categories[0]];
            foreach ($this->locales as $locale) {
                $description = $locale === $this->defaultLocale
                    ? \sprintf('Синтетична картка %d для навантажувального тесту каталогу: %d варіант(и), %d характеристик.', $id, \count($p->variants), \count($p->attributes))
                    : null;
                $translations[] = [$this->id('catalog_product_translation'), $locale, $this->names->product($id, $locale), $this->names->productSlug($id, $locale), $description, $id];
            }
            foreach ($p->categories as $categoryIndex) {
                $categories[] = [$id, $categoryIndex];
            }
            foreach ($p->attributes as $attributeIndex => $value) {
                switch ($attributes[$attributeIndex]->type) {
                    case AttributeType::Select:
                    case AttributeType::MultiSelect:
                        foreach ((array) $value as $option) {
                            $values[] = [$this->id('catalog_product_attribute_value'), null, null, $id, $attributeIndex, $valueIds[$attributeIndex][(int) $option]];
                        }
                        break;
                    case AttributeType::Number:
                        $values[] = [$this->id('catalog_product_attribute_value'), (string) $value, null, $id, $attributeIndex, null];
                        break;
                    case AttributeType::Boolean:
                        $values[] = [$this->id('catalog_product_attribute_value'), null, true === $value ? 1 : 0, $id, $attributeIndex, null];
                        break;
                }
            }
            foreach ($p->variants as $position => $v) {
                $variantId = $this->id('catalog_product_variant');
                $variants[] = [
                    $variantId, SyntheticNames::sku($id, $position), SyntheticNames::externalId($id, $position), $v->priceMinor, $v->oldPriceMinor, 'UAH',
                    $v->stock.'.000', $v->policy->value, StockPolicy::MadeToOrder === $v->policy ? 14 : null, 'pc', '1.000', '1.000', $position, 1, $at, $at, $id,
                ];
                if (null !== $axis && null !== $v->axisValue) {
                    $axisRows[] = [$this->id('catalog_product_variant_axis_value'), $variantId, $axis->index, $valueIds[$axis->index][$v->axisValue]];
                }
            }
        }

        $this->transaction(function () use ($products, $translations, $categories, $variants, $values, $axisRows): void {
            $this->insert('catalog_product', ['id', 'kind', 'price_mode', 'active', 'created_at', 'updated_at', 'brand_id', 'main_category_id'], $products);
            $this->insert('catalog_product_translation', ['id', 'locale', 'name', 'slug', 'description', 'product_id'], $translations);
            $this->insert('catalog_product_category', ['product_id', 'category_id'], $categories);
            $this->insert('catalog_product_variant', ['id', 'sku', 'external_id', 'price_minor', 'old_price_minor', 'currency', 'stock', 'stock_policy',
                'lead_time_days', 'unit', 'min_quantity', 'quantity_step', 'position', 'active', 'created_at', 'updated_at', 'product_id'], $variants);
            $this->insert('catalog_product_attribute_value', ['id', 'number_value', 'boolean_value', 'product_id', 'attribute_id', 'option_id'], $values);
            $this->insert('catalog_product_variant_axis_value', ['id', 'variant_id', 'attribute_id', 'attribute_value_id'], $axisRows);
        });

        $counts['products'] += \count($products);
        $counts['product_translations'] += \count($translations);
        $counts['product_categories'] += \count($categories);
        $counts['variants'] += \count($variants);
        $counts['attribute_rows'] += \count($values);
        $counts['axis_rows'] += \count($axisRows);
    }

    /**
     * Багаторядковий INSERT; великі масиви ріжуться на шматки по 5000 рядків (max_allowed_packet).
     *
     * @param list<string>                           $columns
     * @param list<list<int|string|float|bool|null>> $rows
     */
    private function insert(string $table, array $columns, array $rows): void
    {
        foreach (array_chunk($rows, 5000) as $chunk) {
            $sql = [];
            foreach ($chunk as $row) {
                $sql[] = '('.implode(',', array_map($this->literal(...), $row)).')';
            }
            $this->db->executeStatement('INSERT INTO '.$table.' ('.implode(',', $columns).') VALUES '.implode(',', $sql));
        }
    }

    private function literal(int|string|float|bool|null $value): string
    {
        return match (true) {
            null === $value => 'NULL',
            \is_int($value) => (string) $value,
            \is_bool($value) => $value ? '1' : '0',
            default => $this->db->quote((string) $value),
        };
    }

    private function id(string $table): int
    {
        return $this->nextId[$table] = ($this->nextId[$table] ?? 0) + 1;
    }

    private function transaction(callable $work): void
    {
        $this->db->beginTransaction();
        try {
            $work();
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
