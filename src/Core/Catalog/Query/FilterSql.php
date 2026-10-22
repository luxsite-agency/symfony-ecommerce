<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

use App\Core\Catalog\FilterIndex\FilterIndexTables;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

/**
 * Будує FROM/JOIN/WHERE над read-index (SQL із consilium-2026-09-27-f0/critic.md): товар × категорія ∪ предки через
 * catalog_filter_product_category (рівність, не IN(піддерево)), один self-JOIN catalog_filter_value на кожен обраний
 * атрибут — усі на тому самому variant_id («42 і червоний» — один варіант), бренд і ціна — по catalog_filter_variant.
 *
 * Наявність (ADR Rev 9): є варіантні предикати (вісь варіанта, ціна) → purchasable=1; «в наявності» → in_stock=1;
 * інакше listed=1. Для фасетів база будується без «власного» предиката (exclude) — disjunctive-лічильники; для
 * осі/ціни forceVariant вмикає purchasable, щоб лічильник збігався з результатом після вибору значення.
 *
 * Id атрибутів вбудовуються в SQL як int (вони з FacetDefinition, не з URL), значення — лише параметрами.
 *
 * @internal використовує CatalogQuery
 */
final class FilterSql
{
    public const string EXCLUDE_BRAND = 'brand';
    public const string EXCLUDE_PRICE = 'price';
    public const string EXCLUDE_STOCK = 'stock';
    public const string EXCLUDE_LETTER = 'letter';

    /** @var array<int, FacetDefinition> */
    private array $definitions = [];

    /**
     * @param list<FacetDefinition> $definitions
     * @param list<int>             $leadOrder   характеристики з опціями в порядку вибірковості (CatalogQuery — за
     *                                           зведенням, лише для великих категорій): першу обрану не виключену база
     *                                           ставить у FROM першою; порожньо — порядок обирає оптимізатор
     */
    public function __construct(private readonly CatalogFilter $filter, array $definitions, private readonly array $leadOrder = [])
    {
        foreach ($definitions as $definition) {
            $this->definitions[$definition->attributeId] = $definition;
        }
    }

    public static function excludeAttribute(int $attributeId): string
    {
        return 'attr:'.$attributeId;
    }

    /** @return array<int, FacetDefinition> обрані у фільтрі й відомі категорії характеристики (невідомі/не того типу — ігноруються) */
    public function selected(): array
    {
        $selected = [];
        foreach ($this->definitions as $id => $definition) {
            if (($definition->hasOptions() && isset($this->filter->options[$id]))
                || ($definition->isNumber() && isset($this->filter->numbers[$id]))
                || ($definition->isBoolean() && isset($this->filter->booleans[$id]))) {
                $selected[$id] = $definition;
            }
        }

        return $selected;
    }

    /** @return array<int, FacetDefinition> */
    public function unselected(): array
    {
        return array_diff_key($this->definitions, $this->selected());
    }

    /** База без предиката exclude вимагає purchasable (або in_stock)? */
    public function isVariantLevel(?string $exclude = null, bool $forceVariant = false): bool
    {
        if ($forceVariant || ($this->filter->inStockOnly && self::EXCLUDE_STOCK !== $exclude)) {
            return true;
        }
        if (!$this->filter->price->isEmpty() && self::EXCLUDE_PRICE !== $exclude) {
            return true;
        }
        foreach ($this->selected() as $id => $definition) {
            if ($definition->variantAxis && self::excludeAttribute($id) !== $exclude) {
                return true;
            }
        }

        return false;
    }

    /**
     * База без предиката exclude — категорія без фільтрів (F0): її лічильники є в зведенні категорії
     * (CategorySummary; рівень наявності там той самий — listed, для осей і ціни purchasable, «в наявності» — in_stock).
     */
    public function isCategoryBase(?string $exclude = null): bool
    {
        if (null === $this->filter->categoryId) {
            return false;
        }
        foreach ($this->selected() as $id => $definition) {
            if (self::excludeAttribute($id) !== $exclude) {
                return false;
            }
        }

        return ([] === $this->filter->brandIds || self::EXCLUDE_BRAND === $exclude)
            && ($this->filter->price->isEmpty() || self::EXCLUDE_PRICE === $exclude)
            && (!$this->filter->inStockOnly || self::EXCLUDE_STOCK === $exclude)
            && (null === $this->filter->letter || self::EXCLUDE_LETTER === $exclude)
            && null === $this->filter->productIds;
    }

    /**
     * «FROM … WHERE …» бази; умови дописувати через « AND …».
     *
     * @return array{string, array<string, mixed>, array<string, ArrayParameterType|ParameterType>}
     */
    public function base(?string $exclude = null, bool $forceVariant = false, string $extraJoin = ''): array
    {
        $lead = $this->lead($exclude);
        [$joins, $where, $params, $types] = $this->parts($exclude, $forceVariant, $lead);
        $joins .= $extraJoin;
        if (null !== $this->filter->categoryId && null !== $lead) {
            // Є обрана опція — «значення → варіант → категорія» (решта JOIN вільні): після ANALYZE оптимізатор для
            // популярних значень вів від категорії (~190 мс на 76k, ~320 мс на 132k на кожен запит бази), а від значення
            // по idx_ff_option — 25–280 мс; A/B на 30 значеннях у двох категоріях: ніде не гірше більш ніж на 7 %.
            // У малій категорії навпаки (215 карток: 5 → 380 мс — обхід усіх рядків популярного значення), тому
            // leadOrder дає лише CatalogQuery для категорій від LEAD_MIN_CATEGORY карток.
            $alias = 'f'.$lead;
            $sql = ' FROM '.FilterIndexTables::VALUE.' '.$alias
                .' STRAIGHT_JOIN '.FilterIndexTables::VARIANT.' v ON v.variant_id = '.$alias.'.variant_id'
                .' STRAIGHT_JOIN '.FilterIndexTables::PRODUCT_CATEGORY.' pc ON pc.product_id = v.product_id'.$joins;
            array_unshift($where, 'pc.category_id = :category', \sprintf('%1$s.attribute_id = %2$d AND %1$s.option_id IN (:o%2$d)', $alias, $lead));
        } elseif (null !== $this->filter->categoryId) {
            // Без опцій — STRAIGHT_JOIN «категорія → варіанти»: після перебудови на 500k оптимізатор обирав повний скан
            // 800k варіантів із пробами в pc (сторінка великої категорії 250 → 670 мс, фасет ціни 190 → 785). Числа
            // й boolean план лишають оптимізатору (boolean невибірковий, вести від нього не можна).
            $sql = ' FROM '.FilterIndexTables::PRODUCT_CATEGORY.' pc '.('' === $joins ? 'STRAIGHT_JOIN' : 'JOIN').' '.FilterIndexTables::VARIANT.' v ON v.product_id = pc.product_id'.$joins;
            array_unshift($where, 'pc.category_id = :category');
        } else {
            $sql = ' FROM '.FilterIndexTables::VARIANT.' v'.$joins;
        }

        return [$sql.' WHERE '.implode(' AND ', $where), $params, $types];
    }

    /**
     * Корельований підзапит «цей варіант входить у базу»: «SELECT 1 FROM … WHERE v.variant_id = $outerVariant AND …» —
     * для MIN/MAX упорядкованим обходом індексу зовнішньої таблиці з LIMIT 1 (ADR Rev 11 п. 1): кожен крок обходу —
     * кілька PK-lookup (варіант, товар × категорія, значення обраних характеристик), а не з'єднання всієї категорії.
     *
     * @return array{string, array<string, mixed>, array<string, ArrayParameterType|ParameterType>}
     */
    public function exists(string $outerVariant, ?string $exclude = null, bool $forceVariant = false): array
    {
        [$joins, $where, $params, $types] = $this->parts($exclude, $forceVariant);
        $sql = 'SELECT 1 FROM '.FilterIndexTables::VARIANT.' v';
        if (null !== $this->filter->categoryId) {
            $sql .= ' JOIN '.FilterIndexTables::PRODUCT_CATEGORY.' pc ON pc.category_id = :category AND pc.product_id = v.product_id';
        }
        array_unshift($where, 'v.variant_id = '.$outerVariant);

        return [$sql.$joins.' WHERE '.implode(' AND ', $where), $params, $types];
    }

    /** Характеристика з опціями, від якої вести базу: перша за leadOrder, обрана й не виключена. */
    private function lead(?string $exclude): ?int
    {
        $candidates = [];
        foreach ($this->selected() as $id => $definition) {
            if ($definition->hasOptions() && self::excludeAttribute($id) !== $exclude) {
                $candidates[] = $id;
            }
        }
        foreach ($this->leadOrder as $id) {
            if (\in_array($id, $candidates, true)) {
                return $id;
            }
        }

        return null;
    }

    /** @return array{string, list<string>, array<string, mixed>, array<string, ArrayParameterType|ParameterType>} JOIN характеристик, умови WHERE, параметри */
    private function parts(?string $exclude, bool $forceVariant, ?int $lead = null): array
    {
        $params = $types = [];
        $where = [];
        $joins = '';
        if (null !== $this->filter->categoryId) {
            $params['category'] = $this->filter->categoryId;
            $types['category'] = ParameterType::INTEGER;
        }

        foreach ($this->selected() as $id => $definition) {
            if (self::excludeAttribute($id) === $exclude) {
                continue;
            }
            $alias = 'f'.$id;
            $on = \sprintf('%1$s.variant_id = v.variant_id AND %1$s.attribute_id = %2$d', $alias, $id);
            if ($definition->hasOptions()) {
                $params['o'.$id] = $this->filter->options[$id];
                $types['o'.$id] = ArrayParameterType::INTEGER;
                if ($id === $lead) {
                    continue; // таблиця вже перша у FROM, умова — у WHERE
                }
                $on .= \sprintf(' AND %s.option_id IN (:o%d)', $alias, $id);
            } elseif ($definition->isNumber()) {
                $range = $this->filter->numbers[$id];
                if (null !== $range->min) {
                    $on .= \sprintf(' AND %s.number_value >= :nmin%d', $alias, $id);
                    $params['nmin'.$id] = $range->min;
                    $types['nmin'.$id] = ParameterType::STRING;
                }
                if (null !== $range->max) {
                    $on .= \sprintf(' AND %s.number_value <= :nmax%d', $alias, $id);
                    $params['nmax'.$id] = $range->max;
                    $types['nmax'.$id] = ParameterType::STRING;
                }
            } else {
                $on .= \sprintf(' AND %s.boolean_value = :b%d', $alias, $id);
                $params['b'.$id] = $this->filter->booleans[$id] ? 1 : 0;
                $types['b'.$id] = ParameterType::INTEGER;
            }
            $joins .= ' JOIN '.FilterIndexTables::VALUE.' '.$alias.' ON '.$on;
        }

        if (null !== $this->filter->letter && self::EXCLUDE_LETTER !== $exclude) {
            // A–Z: перша літера назви мовою сторінки (LIKE 'a%' — регістр і діакритика за колацією; префікс — індексом не
            // користується, бо з'єднання йде від варіантів до перекладу по product_id)
            $joins .= ' JOIN catalog_product_translation tl ON tl.product_id = v.product_id AND tl.locale = :locale AND tl.name LIKE :letter';
            $params['locale'] = $this->filter->locale;
            $types['locale'] = ParameterType::STRING;
            $params['letter'] = addcslashes($this->filter->letter, '%_\\').'%';
            $types['letter'] = ParameterType::STRING;
        }
        if (null !== $this->filter->productIds) {
            // пошук (/search): база — лише знайдені товари; порожній список — порожній результат без обходу індексу
            if ([] === $this->filter->productIds) {
                $where[] = '0 = 1';
            } else {
                $where[] = 'v.product_id IN (:ids)';
                $params['ids'] = $this->filter->productIds;
                $types['ids'] = ArrayParameterType::INTEGER;
            }
        }
        if ([] !== $this->filter->brandIds && self::EXCLUDE_BRAND !== $exclude) {
            $where[] = 'v.brand_id IN (:brands)';
            $params['brands'] = $this->filter->brandIds;
            $types['brands'] = ArrayParameterType::INTEGER;
        }
        if (!$this->filter->price->isEmpty() && self::EXCLUDE_PRICE !== $exclude) {
            $where[] = \sprintf('v.price_mode IN (%d, %d)', CatalogHit::PRICE_FIXED, CatalogHit::PRICE_FROM);
            if (null !== $this->filter->price->min) {
                $where[] = 'v.price_minor >= :pmin';
                $params['pmin'] = $this->filter->price->min;
                $types['pmin'] = ParameterType::INTEGER;
            }
            if (null !== $this->filter->price->max) {
                $where[] = 'v.price_minor <= :pmax';
                $params['pmax'] = $this->filter->price->max;
                $types['pmax'] = ParameterType::INTEGER;
            }
        }

        $where[] = match (true) {
            $this->filter->inStockOnly && self::EXCLUDE_STOCK !== $exclude => 'v.in_stock = 1',
            $this->isVariantLevel($exclude, $forceVariant) => 'v.purchasable = 1',
            default => 'v.listed = 1',
        };

        return [$joins, $where, $params, $types];
    }
}
