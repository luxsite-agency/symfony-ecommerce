<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Query;

use App\Core\Catalog\FilterIndex\FilterIndexer;
use App\Core\Catalog\FilterIndex\FilterIndexTables;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Лістинг категорії з фасетами над read-index (ADR Rev 9, SQL — consilium-2026-09-27-f0/critic.md; продуктивність —
 * ADR Rev 11, consilium-2026-09-27-bench/critic.md).
 *
 * Категорія без фільтрів (F0): сторінка — обхід індексу catalog_filter_product_category за listed/sort_asc/sort_desc
 * (без GROUP BY і filesort), total — COUNT(*) по тому ж індексу, фасети — зі зведення категорії (CategorySummary).
 * З фільтрами: сторінка — GROUP BY product_id (rollup варіантів до картки), total — COUNT(DISTINCT), фасети:
 * необрані атрибути — одним запитом на рівень наявності, кожен обраний — над базою без його власного JOIN
 * (disjunctive); база фасета, що збігається з F0 (напр. єдиний обраний атрибут), — знову зі зведення.
 *
 * Лічильники (FacetCounting, налаштування магазину): вимкнені — значення зі зведення без цифр; база фасета за оцінкою
 * зверху більша за поріг N — цей фасет без цифр; дорогі запити в обох випадках не виконуються. MIN/MAX чисел і ціни
 * на великих базах — упорядкованим обходом індексу з LIMIT 1 (з обмеженням часу й fallback на агрегат).
 *
 * search() кешує результат у versioned namespace CatalogCacheVersion (bump після імпорту/регенерації/перебудови);
 * точкові зміни залишку/ціни версію не міняють — їх покриває TTL. Некешований розрахунок фасетів обмежує
 * ListingRateLimit: понад ліміт — без лічильників і без запису в кеш. execute() — без кешу (benchmark, тести).
 */
final class CatalogQuery
{
    public const int TTL = 300;
    /** Від якої оцінки бази (карток) MIN/MAX рахуються через ORDER BY … LIMIT 1: на малих базах агрегат дешевший. */
    public const int ORDERED_RANGE_MIN_BASE = 2000;
    /** Межа одного LIMIT 1-запиту: на порожній/розрідженій базі обхід іде по всьому індексу атрибута (~0,5 с на 500k). */
    private const int ORDERED_RANGE_TIMEOUT_MS = 50;
    /** Від якого розміру категорії (карток) база з обраною опцією веде від значення, а не від категорії (FilterSql). */
    public const int LEAD_MIN_CATEGORY = 20000;
    private const int MYSQL_QUERY_TIMEOUT = 3024;
    private const string SUMMARY = 'summary';
    private const string EXACT = 'exact';
    private const string UNKNOWN = 'unknown';

    public function __construct(
        private readonly Connection $db,
        private readonly CategoryFacetResolver $facetResolver,
        private readonly CatalogCacheVersion $version,
        #[Autowire(service: 'cache.app')] private readonly CacheInterface $cache,
        private readonly CategorySummaryStore $summaries,
        private readonly FacetCountPolicy $policy,
        private readonly ListingRateLimit $rateLimit,
        private readonly int $orderedRangeMinBase = self::ORDERED_RANGE_MIN_BASE,
    ) {
    }

    /**
     * Два записи кешу: total + фасети — за ключем фільтра БЕЗ сторінки/сортування/курсора (вони від них не залежать),
     * сторінка карток — за повним ключем. Без розділення кожна нова сторінка (?page=N) чи сортування перераховувала б
     * фасети заново.
     */
    public function search(CatalogFilter $filter): CatalogResult
    {
        $version = $this->version->current();
        $definitions = $this->facetResolver->resolve($filter->categoryId);
        $counting = $this->policy->current();
        // FilterSql читає зведення (порядок ведучої характеристики) — лише на промаху кешу
        $lazySql = null;
        $sqlOf = function () use (&$lazySql, $filter, $definitions): FilterSql {
            return $lazySql ??= $this->filterSql($filter, $definitions);
        };

        /** @var array{int, CatalogFacets} $summary */
        // f2: CatalogFacets отримав letters — старий серіалізований запис без властивості не підходить
        $summary = $this->cache->get('catalog.f2.'.$version.'.'.$counting->key().'.'.$filter->facetKey(), function (ItemInterface $item, bool &$save) use ($sqlOf, $filter, $counting): array {
            $item->expiresAfter(self::TTL);
            $sql = $sqlOf();
            if (!$this->rateLimit->consume()) {
                $save = false; // урізаний результат не кешуємо: наступний відвідувач у межах ліміту отримає повний
                $counting = $counting->degrade();
            }
            $total = $this->total($sql, $filter);

            return [$total, $this->facets($sql, $filter, $total, $counting)];
        });
        /** @var array{list<CatalogHit>, array{int, int}|null} $page */
        $page = $this->cache->get('catalog.p.'.$version.'.'.$filter->cacheKey(), function (ItemInterface $item) use ($sqlOf, $filter): array {
            $item->expiresAfter(self::TTL);

            return $this->page($sqlOf(), $filter);
        });

        return new CatalogResult($page[0], $summary[0], null === $filter->after ? $filter->page : 1, $filter->perPage, $summary[1], $definitions, $page[1]);
    }

    /**
     * Без кешу результатів (фасетний блок категорії — через кеш резолвера). $withTotal = false — без COUNT
     * (total = -1): keyset-гортання далі першої сторінки і benchmark сторінки окремо від лічильника.
     * $counting = null — з налаштувань магазину.
     */
    public function execute(CatalogFilter $filter, bool $withFacets = true, bool $withTotal = true, ?FacetCounting $counting = null): CatalogResult
    {
        $definitions = $this->facetResolver->resolve($filter->categoryId);
        $sql = $this->filterSql($filter, $definitions);

        [$hits, $nextCursor] = $this->page($sql, $filter);
        $total = $withTotal || $withFacets ? $this->total($sql, $filter) : -1;
        $facets = $withFacets ? $this->facets($sql, $filter, $total, $counting ?? $this->policy->current()) : new CatalogFacets();

        return new CatalogResult($hits, $withTotal ? $total : -1, null === $filter->after ? $filter->page : 1, $filter->perPage, $facets, $definitions, $nextCursor);
    }

    /**
     * Зведення категорії без фільтрів для CategorySummaryStore: усі filterable-характеристики (не лише блок категорії —
     * список категорії може змінитись без перерахунку), точні лічильники.
     *
     * @return array{int, CatalogFacets}
     */
    public function summarize(int $categoryId): array
    {
        $filter = new CatalogFilter($categoryId);
        $sql = new FilterSql($filter, $this->facetResolver->load(null));
        $total = $this->total($sql, $filter);

        return [$total, $this->facets($sql, $filter, $total, FacetCounting::exact(), false)];
    }

    /**
     * Ведуча характеристика бази (лише у великих категоріях) — за F0-лічильниками обраних опцій у зведенні (менше
     * карток — вибірковіше).
     *
     * @param list<FacetDefinition> $definitions
     */
    private function filterSql(CatalogFilter $filter, array $definitions): FilterSql
    {
        $summary = [] !== $filter->options && null !== $filter->categoryId ? $this->summaries->get($filter->categoryId) : null;
        $f0 = null !== $summary && $summary->total >= self::LEAD_MIN_CATEGORY ? $summary->facets : null;
        $order = [];
        if (null !== $f0) {
            foreach ($filter->options as $id => $optionIds) {
                $order[$id] = array_sum(array_map(static fn (int $o): int => $f0->optionCount($id, $o) ?? 0, $optionIds));
            }
            asort($order);
        }

        return new FilterSql($filter, $definitions, array_keys($order));
    }

    /** @return array{list<CatalogHit>, array{int, int}|null} */
    private function page(FilterSql $sql, CatalogFilter $filter): array
    {
        if ($sql->isCategoryBase()) {
            return $this->categoryPage($filter);
        }
        [$from, $params, $types] = $sql->base();
        [$sortKey, $direction] = match ($filter->sort) {
            CatalogSort::PriceAsc => [\sprintf('MIN(IF(v.price_mode = %d, %d, v.price_minor))', CatalogHit::PRICE_ON_REQUEST, FilterIndexer::SORT_LAST_ASC), 'ASC'],
            CatalogSort::PriceDesc => [\sprintf('MIN(IF(v.price_mode = %d, %d, v.price_minor))', CatalogHit::PRICE_ON_REQUEST, FilterIndexer::SORT_LAST_DESC), 'DESC'],
            CatalogSort::Newest => ['v.product_id', 'DESC'],
        };

        $having = '';
        $offset = ($filter->page - 1) * $filter->perPage;
        if (null !== $filter->after) {
            $cmp = 'ASC' === $direction ? '>' : '<';
            $having = \sprintf(' HAVING (sort_key %1$s :after_key OR (sort_key = :after_key AND v.product_id %1$s :after_id))', $cmp);
            $params['after_key'] = $filter->after[0];
            $params['after_id'] = $filter->after[1];
            $types['after_key'] = ParameterType::INTEGER;
            $types['after_id'] = ParameterType::INTEGER;
            $offset = 0;
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT v.product_id, MIN(v.price_minor) AS min_price, MAX(v.price_mode) AS price_mode, '.$sortKey.' AS sort_key'
            .$from.' GROUP BY v.product_id'.$having
            .\sprintf(' ORDER BY sort_key %1$s, v.product_id %1$s LIMIT %2$d OFFSET %3$d', $direction, $filter->perPage + 1, $offset),
            $params,
            $types,
        );

        return $this->hits($rows, $filter->perPage);
    }

    /**
     * Сторінка F0 (ADR Rev 11 п. 3): діапазон idx_fpc_sort_asc / idx_fpc_sort_desc (новизна — PK), потім ціна й
     * price_mode 24 карток за idx_fv_product. Ключі сортування ті самі, що в GROUP BY-гілці, тож курсори сумісні.
     *
     * @return array{list<CatalogHit>, array{int, int}|null}
     */
    private function categoryPage(CatalogFilter $filter): array
    {
        [$column, $direction] = match ($filter->sort) {
            CatalogSort::PriceAsc => ['pc.sort_asc', 'ASC'],
            CatalogSort::PriceDesc => ['pc.sort_desc', 'DESC'],
            CatalogSort::Newest => ['pc.product_id', 'DESC'],
        };
        $params = ['category' => $filter->categoryId];
        $types = ['category' => ParameterType::INTEGER];
        $where = '';
        $offset = ($filter->page - 1) * $filter->perPage;
        if (null !== $filter->after) {
            $cmp = 'ASC' === $direction ? '>' : '<';
            $where = \sprintf(' AND (%1$s %2$s :after_key OR (%1$s = :after_key AND pc.product_id %2$s :after_id))', $column, $cmp);
            $params += ['after_key' => $filter->after[0], 'after_id' => $filter->after[1]];
            $types += ['after_key' => ParameterType::INTEGER, 'after_id' => ParameterType::INTEGER];
            $offset = 0;
        }
        $order = 'pc.product_id' === $column ? 'pc.product_id '.$direction : \sprintf('%1$s %2$s, pc.product_id %2$s', $column, $direction);
        /** @var array<int|string, int|string> $keys product_id → ключ сортування, у порядку сторінки */
        $keys = $this->db->fetchAllKeyValue(
            'SELECT pc.product_id, '.$column.' FROM '.FilterIndexTables::PRODUCT_CATEGORY.' pc'
            .' WHERE pc.category_id = :category AND pc.listed = 1'.$where
            .\sprintf(' ORDER BY %s LIMIT %d OFFSET %d', $order, $filter->perPage + 1, $offset),
            $params,
            $types,
        );
        if ([] === $keys) {
            return [[], null];
        }
        $prices = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT product_id, MIN(price_minor) AS min_price, MAX(price_mode) AS price_mode FROM '.FilterIndexTables::VARIANT
            .' WHERE product_id IN (?) AND listed = 1 GROUP BY product_id',
            [array_map(intval(...), array_keys($keys))],
            [ArrayParameterType::INTEGER],
        ) as $row) {
            $prices[(int) $row['product_id']] = $row;
        }
        $rows = [];
        foreach ($keys as $productId => $sortKey) {
            $rows[] = ['product_id' => $productId, 'sort_key' => $sortKey, 'min_price' => $prices[(int) $productId]['min_price'] ?? 0, 'price_mode' => $prices[(int) $productId]['price_mode'] ?? CatalogHit::PRICE_ON_REQUEST];
        }

        return $this->hits($rows, $filter->perPage);
    }

    /**
     * @param list<array<string, mixed>> $rows product_id, min_price, price_mode, sort_key; на один більше за сторінку
     *
     * @return array{list<CatalogHit>, array{int, int}|null}
     */
    private function hits(array $rows, int $perPage): array
    {
        $more = \count($rows) > $perPage;
        $rows = \array_slice($rows, 0, $perPage);
        $hits = array_map(static fn (array $r): CatalogHit => new CatalogHit((int) $r['product_id'], (int) $r['min_price'], (int) $r['price_mode']), $rows);
        $last = end($rows);
        $cursor = $more && false !== $last ? [(int) $last['sort_key'], (int) $last['product_id']] : null;

        return [$hits, $cursor];
    }

    private function total(FilterSql $sql, CatalogFilter $filter): int
    {
        if ($sql->isCategoryBase()) {
            return (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.FilterIndexTables::PRODUCT_CATEGORY.' WHERE category_id = ? AND listed = 1', [$filter->categoryId]);
        }
        [$from, $params, $types] = $sql->base();

        return (int) $this->db->fetchOne('SELECT COUNT(DISTINCT v.product_id)'.$from, $params, $types);
    }

    /** $useSummary = false — рахувати все запитами (сам перерахунок зведення). */
    private function facets(FilterSql $sql, CatalogFilter $filter, int $total, FacetCounting $counting, bool $useSummary = true): CatalogFacets
    {
        $summary = $useSummary && null !== $filter->categoryId ? $this->summary($filter->categoryId) : null;
        $f0 = $summary?->facets;
        $options = $booleans = $numbers = [];
        $unselected = $sql->unselected();

        // Необрані характеристики з опціями/boolean: один запит на рівень наявності (осі — purchasable); база = весь
        // відфільтрований набір, тож її розмір — рівно total
        $groups = [];
        foreach ($unselected as $id => $definition) {
            if (!$definition->isNumber()) {
                $groups[$sql->isVariantLevel(null, $definition->variantAxis) ? 1 : 0][] = $id;
            }
        }
        $mode = $this->mode($sql->isCategoryBase(), $total, $summary, $counting);
        foreach ($groups as $variantLevel => $attributeIds) {
            $this->discrete($mode, $sql->base(null, 1 === $variantLevel), $attributeIds, $f0, $options, $booleans);
        }

        // Необрані числові (числові характеристики не бувають осями)
        $numberIds = array_keys(array_filter($unselected, static fn (FacetDefinition $d): bool => $d->isNumber()));
        if ([] !== $numberIds) {
            $this->numbers($sql, null, $numberIds, $total, $f0, $counting, $numbers);
        }

        // Кожна обрана — над базою без власного фільтра
        foreach ($sql->selected() as $id => $definition) {
            $exclude = FilterSql::excludeAttribute($id);
            $estimate = $this->estimate($sql, $filter, $exclude, $summary, $total);
            if ($definition->isNumber()) {
                $this->numbers($sql, $exclude, [$id], $estimate, $f0, $counting, $numbers);
            } else {
                $mode = $this->mode($sql->isCategoryBase($exclude), $estimate, $summary, $counting);
                $this->discrete($mode, $sql->base($exclude, $definition->variantAxis), [$id], $f0, $options, $booleans);
            }
        }
        foreach ($options as $attributeId => $counts) {
            ksort($counts);
            $options[$attributeId] = $counts;
        }

        $estimate = $this->estimate($sql, $filter, FilterSql::EXCLUDE_BRAND, $summary, $total);
        $brands = match ($this->mode($sql->isCategoryBase(FilterSql::EXCLUDE_BRAND), $estimate, $summary, $counting)) {
            self::SUMMARY => $f0->brands ?? [],
            self::EXACT => $this->brands($sql),
            default => CatalogFacets::unknown($f0->brands ?? []),
        };
        $estimate = $this->estimate($sql, $filter, FilterSql::EXCLUDE_STOCK, $summary, $total);
        $inStock = match ($this->mode($sql->isCategoryBase(FilterSql::EXCLUDE_STOCK), $estimate, $summary, $counting)) {
            self::SUMMARY => $f0?->inStock,
            self::EXACT => $this->inStock($sql),
            default => null,
        };

        return new CatalogFacets($options, $booleans, $numbers, $brands, $this->priceRange($sql, $filter, $summary, $total, $counting), $inStock, $filter->letters ? $this->letters($sql, $filter) : []);
    }

    /**
     * Лічильники A–Z: перша літера назви мовою фільтра → картки, над базою без власного фільтра літери (disjunctive, як
     * обраний фасет). Один GROUP BY по базі — вмикається паком (app.catalog_letters), кешується разом із фасетами.
     *
     * @return array<string, int>
     */
    private function letters(FilterSql $sql, CatalogFilter $filter): array
    {
        if ('' === $filter->locale) {
            return [];
        }
        [$from, $params, $types] = $sql->base(FilterSql::EXCLUDE_LETTER, false, ' JOIN catalog_product_translation tl ON tl.product_id = v.product_id AND tl.locale = :locale');
        $params['locale'] = $filter->locale;
        $types['locale'] = ParameterType::STRING;
        $letters = [];
        foreach ($this->db->fetchAllAssociative('SELECT UPPER(LEFT(tl.name, 1)) AS letter, COUNT(DISTINCT v.product_id) AS cards'.$from.' GROUP BY letter', $params, $types) as $row) {
            $letter = (string) $row['letter'];
            if (1 === preg_match('/^\p{L}$/u', $letter)) {
                $letters[$letter] = (int) $row['cards'];
            }
        }
        ksort($letters, \SORT_STRING);

        return $letters;
    }

    /**
     * Зведення категорії; нема (нова категорія, до першої перебудови) — порахувати зараз і зберегти.
     */
    private function summary(int $categoryId): CategorySummary
    {
        $summary = $this->summaries->get($categoryId);
        if (null === $summary) {
            [$total, $facets] = $this->summarize($categoryId);
            $this->summaries->save($categoryId, $total, $facets);
            $summary = $this->summaries->get($categoryId) ?? throw new LogicException('Зведення категорії не збереглось');
        }

        return $summary;
    }

    /** Звідки брати лічильники фасета: зі зведення (база = F0), точним запитом (база ≤ N) чи ніяк (цифри нема). */
    private function mode(bool $categoryBase, int $estimate, ?CategorySummary $summary, FacetCounting $counting): string
    {
        return match (true) {
            !$counting->counts() => self::UNKNOWN,
            $categoryBase && null !== $summary => self::SUMMARY,
            $estimate <= $counting->maxSet => self::EXACT,
            default => self::UNKNOWN,
        };
    }

    /**
     * Оцінка зверху розміру бази фасета (карток): база без власного предиката ⊂ категорії і ⊂ кожної решти обраних
     * умов, тож її розмір ≤ мінімуму F0-лічильників обраних значень (зведення). Без exclude база — весь набір (total).
     * Без зведення і з exclude — невідомо (PHP_INT_MAX).
     */
    private function estimate(FilterSql $sql, CatalogFilter $filter, string $exclude, ?CategorySummary $summary, int $total): int
    {
        if (null === $summary) {
            return \PHP_INT_MAX;
        }
        $f0 = $summary->facets;
        $bound = $summary->total;
        foreach ($sql->selected() as $id => $definition) {
            if (FilterSql::excludeAttribute($id) === $exclude) {
                continue;
            }
            if ($definition->hasOptions()) {
                $bound = min($bound, array_sum(array_map(static fn (int $o): int => $f0->optionCount($id, $o) ?? 0, $filter->options[$id])));
            } elseif ($definition->isBoolean()) {
                $bound = min($bound, $f0->booleans[$id][(int) $filter->booleans[$id]] ?? 0);
            }
        }
        if ([] !== $filter->brandIds && FilterSql::EXCLUDE_BRAND !== $exclude) {
            $bound = min($bound, array_sum(array_map(static fn (int $b): int => $f0->brands[$b] ?? 0, $filter->brandIds)));
        }
        if ($filter->inStockOnly && FilterSql::EXCLUDE_STOCK !== $exclude) {
            $bound = min($bound, $f0->inStock ?? $bound);
        }

        return max($bound, $total);
    }

    /**
     * @param array{string, array<string, mixed>, array<string, ArrayParameterType|ParameterType>} $base
     * @param list<int>                                                                            $attributeIds
     * @param array<int, array<int, int|null>>                                                     $options
     * @param array<int, array<int, int|null>>                                                     $booleans
     */
    private function discrete(string $mode, array $base, array $attributeIds, ?CatalogFacets $f0, array &$options, array &$booleans): void
    {
        if (self::EXACT === $mode) {
            $this->collectDiscrete($base, $attributeIds, $options, $booleans);

            return;
        }
        foreach ($attributeIds as $id) {
            if (isset($f0->options[$id])) {
                $options[$id] = self::UNKNOWN === $mode ? CatalogFacets::unknown($f0->options[$id]) : $f0->options[$id];
            }
            if (isset($f0->booleans[$id])) {
                $booleans[$id] = self::UNKNOWN === $mode ? CatalogFacets::unknown($f0->booleans[$id]) : $f0->booleans[$id];
            }
        }
    }

    /**
     * @param array{string, array<string, mixed>, array<string, ArrayParameterType|ParameterType>} $base
     * @param list<int>                                                                            $attributeIds
     * @param array<int, array<int, int|null>>                                                     $options
     * @param array<int, array<int, int|null>>                                                     $booleans
     */
    private function collectDiscrete(array $base, array $attributeIds, array &$options, array &$booleans): void
    {
        [$from, $params, $types] = $base;
        $rows = $this->db->fetchAllAssociative(
            'SELECT f.attribute_id, f.option_id, f.boolean_value, COUNT(DISTINCT f.product_id) AS cnt'
            .' FROM (SELECT DISTINCT v.variant_id'.$from.') m'
            .' JOIN '.FilterIndexTables::VALUE.' f ON f.variant_id = m.variant_id'
            .' WHERE f.attribute_id IN ('.self::intList($attributeIds).') AND f.number_value IS NULL'
            .' GROUP BY f.attribute_id, f.option_id, f.boolean_value',
            $params,
            $types,
        );
        foreach ($rows as $r) {
            $attributeId = (int) $r['attribute_id'];
            if (0 !== (int) $r['option_id']) {
                $options[$attributeId][(int) $r['option_id']] = (int) $r['cnt'];
            } elseif (null !== $r['boolean_value']) {
                $booleans[$attributeId][(int) $r['boolean_value']] = (int) $r['cnt'];
            }
        }
    }

    /**
     * MIN/MAX числових характеристик: база = F0 або rate limit — зі зведення; велика база — ORDER BY … LIMIT 1 по
     * idx_ff_number (ADR Rev 11 п. 1: 616 → ~4 мс на 132k); мала — агрегатом (без DISTINCT: для MIN/MAX дублі не важать).
     *
     * @param list<int>               $attributeIds
     * @param array<int, NumberRange> $numbers
     */
    private function numbers(FilterSql $sql, ?string $exclude, array $attributeIds, int $estimate, ?CatalogFacets $f0, FacetCounting $counting, array &$numbers): void
    {
        if (null !== $f0 && ($counting->degraded || $sql->isCategoryBase($exclude))) {
            foreach ($attributeIds as $id) {
                if (isset($f0->numbers[$id])) {
                    $numbers[$id] = $f0->numbers[$id];
                }
            }

            return;
        }
        $aggregate = [];
        foreach ($attributeIds as $id) {
            if (null !== $f0 && !isset($f0->numbers[$id])) {
                continue; // у категорії цього числа нема зовсім — і у відфільтрованому наборі не буде
            }
            if ($estimate < $this->orderedRangeMinBase) {
                $aggregate[] = $id;
                continue;
            }
            [$exists, $params, $types] = $sql->exists('o.variant_id', $exclude);
            $bounds = $this->orderedBounds(
                'SELECT o.number_value FROM '.FilterIndexTables::VALUE.' o FORCE INDEX (idx_ff_number)'
                .\sprintf(' WHERE o.attribute_id = %d AND o.number_value IS NOT NULL AND EXISTS (%s) ORDER BY o.number_value', $id, $exists),
                $params,
                $types,
            );
            if (false === $bounds) {
                $aggregate[] = $id;
            } elseif (null !== $bounds) {
                $numbers[$id] = new NumberRange((string) $bounds[0], (string) $bounds[1]);
            }
        }
        if ([] === $aggregate) {
            return;
        }
        [$from, $params, $types] = $sql->base($exclude);
        $rows = $this->db->fetchAllAssociative(
            'SELECT f.attribute_id, MIN(f.number_value) AS min_value, MAX(f.number_value) AS max_value'
            .' FROM (SELECT v.variant_id'.$from.') m'
            .' JOIN '.FilterIndexTables::VALUE.' f ON f.variant_id = m.variant_id'
            .' WHERE f.attribute_id IN ('.self::intList($aggregate).') AND f.number_value IS NOT NULL'
            .' GROUP BY f.attribute_id',
            $params,
            $types,
        );
        foreach ($rows as $r) {
            $numbers[(int) $r['attribute_id']] = new NumberRange((string) $r['min_value'], (string) $r['max_value']);
        }
    }

    /**
     * MIN і MAX одним упорядкованим обходом у кожен бік: «$select … ORDER BY x» + « LIMIT 1» / « DESC LIMIT 1».
     * Кожен запит обмежений MAX_EXECUTION_TIME — розріджена чи порожня база обходить індекс цілком.
     *
     * @param array<string, mixed>                            $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     *
     * @return array{int|string, int|string}|false|null null — значень нема; false — не вклалось у час, рахувати агрегатом
     */
    private function orderedBounds(string $select, array $params, array $types): array|false|null
    {
        $hinted = 'SELECT /*+ MAX_EXECUTION_TIME('.self::ORDERED_RANGE_TIMEOUT_MS.') */'.substr($select, \strlen('SELECT'));
        try {
            $min = $this->db->fetchOne($hinted.' ASC LIMIT 1', $params, $types);
            if (false === $min) {
                return null;
            }
            $max = $this->db->fetchOne($hinted.' DESC LIMIT 1', $params, $types);
        } catch (DriverException $e) {
            if (self::MYSQL_QUERY_TIMEOUT !== $e->getCode()) {
                throw $e;
            }

            return false;
        }

        return false === $max ? null : [$min, $max];
    }

    /** @return array<int, int> */
    private function brands(FilterSql $sql): array
    {
        [$from, $params, $types] = $sql->base(FilterSql::EXCLUDE_BRAND);
        $rows = $this->db->fetchAllAssociative(
            'SELECT v.brand_id, COUNT(DISTINCT v.product_id) AS cnt'.$from.' AND v.brand_id IS NOT NULL GROUP BY v.brand_id ORDER BY v.brand_id',
            $params,
            $types,
        );
        $brands = [];
        foreach ($rows as $r) {
            $brands[(int) $r['brand_id']] = (int) $r['cnt'];
        }

        return $brands;
    }

    /** Ціна: як numbers() — зведення, LIMIT 1 по idx_fv_purch_price (171 → ~7 мс на 132k) або агрегат. */
    private function priceRange(FilterSql $sql, CatalogFilter $filter, ?CategorySummary $summary, int $total, FacetCounting $counting): ?PriceRange
    {
        if (null !== $summary && ($counting->degraded || $sql->isCategoryBase(FilterSql::EXCLUDE_PRICE))) {
            return $summary->facets->price;
        }
        if (null !== $summary && null === $summary->facets->price) {
            return null;
        }
        $modes = \sprintf('%d, %d', CatalogHit::PRICE_FIXED, CatalogHit::PRICE_FROM);
        $estimate = $filter->price->isEmpty() ? $total : $this->estimate($sql, $filter, FilterSql::EXCLUDE_PRICE, $summary, $total);
        if ($estimate >= $this->orderedRangeMinBase) {
            [$exists, $params, $types] = $sql->exists('o.variant_id', FilterSql::EXCLUDE_PRICE, true);
            $bounds = $this->orderedBounds(
                'SELECT o.price_minor FROM '.FilterIndexTables::VARIANT.' o FORCE INDEX (idx_fv_purch_price)'
                .' WHERE o.purchasable = 1 AND o.price_mode IN ('.$modes.') AND EXISTS ('.$exists.') ORDER BY o.price_minor',
                $params,
                $types,
            );
            if (false !== $bounds) {
                return null === $bounds ? null : new PriceRange((int) $bounds[0], (int) $bounds[1]);
            }
        }
        [$from, $params, $types] = $sql->base(FilterSql::EXCLUDE_PRICE, true);
        $row = $this->db->fetchAssociative('SELECT MIN(v.price_minor) AS min_price, MAX(v.price_minor) AS max_price'.$from.' AND v.price_mode IN ('.$modes.')', $params, $types);
        if (false === $row || null === $row['min_price']) {
            return null;
        }

        return new PriceRange((int) $row['min_price'], (int) $row['max_price']);
    }

    private function inStock(FilterSql $sql): int
    {
        [$from, $params, $types] = $sql->base(FilterSql::EXCLUDE_STOCK);

        return (int) $this->db->fetchOne('SELECT COUNT(DISTINCT v.product_id)'.$from.' AND v.in_stock = 1', $params, $types);
    }

    /** @param list<int> $ids */
    private static function intList(array $ids): string
    {
        return implode(', ', array_map(static fn (int $id): string => (string) $id, $ids));
    }
}
