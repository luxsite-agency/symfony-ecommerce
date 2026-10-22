<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Command;

use App\Core\Catalog\FilterIndex\FilterIndexer;
use App\Core\Catalog\FilterIndex\FilterIndexRebuilder;
use App\Core\Catalog\FilterIndex\FilterIndexTables;
use App\Core\Catalog\Query\CatalogFilter;
use App\Core\Catalog\Query\CatalogQuery;
use App\Core\Catalog\Query\CatalogResult;
use App\Core\Catalog\Query\CatalogSort;
use App\Core\Catalog\Query\CategoryFacetResolver;
use App\Core\Catalog\Query\FacetCounting;
use App\Core\Catalog\Query\FacetCountPolicy;
use App\Core\Catalog\Query\FacetDefinition;
use App\Core\Catalog\Query\PriceRange;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Benchmark каталогу на read-index (Ф1, ADR Rev 9 D3; поріг sidecar — p95 фасетів > 300 мс без кешу):
 *
 * 1. повна перебудова індексу (shadow + RENAME) і розмір таблиць індексу;
 * 2. для великої (розділ з найбільшою кількістю карток), середньої (найбільший лист) і малої категорії × 0/1/3
 *    фільтри × з лічильниками фасетів і без: «без кешу» — CatalogQuery::execute() з різними значеннями фільтрів
 *    на кожному повторі (MySQL buffer pool не скидається — справжній холодний старт після рестарту MySQL тут не
 *    міряється), «повтор SQL» — той самий запит N разів без кешу, «кеш» — search() з влучанням у cache.app;
 * 3. пагінація великої категорії: OFFSET проти keyset-курсора на сторінках 1…1000 (сторінка без COUNT);
 * 4. інкрементальна переіндексація N випадкових товарів пакетами по 200 (як ReindexProducts), точковий refresh
 *    варіантів (вебхук залишку) і регенерація одного товару.
 *
 * p50/p95 — nearest-rank з N повторів. Результат — таблиці в консоль і JSON (--output).
 *
 * --facet-counts (ADR Rev 11 п. 5): enabled — лічильники з порогом --max-set (як у магазині з увімкненими
 * лічильниками), disabled — без лічильників (дефолт магазину), exact — точні лічильники за будь-якої бази (як до Rev 11).
 */
#[AsCommand(name: 'catalog:benchmark', description: 'Benchmark лістингу категорій на read-index: фільтри, фасети, пагінація, перебудова й переіндексація')]
final class CatalogBenchmarkCommand
{
    private const int REINDEX_CHUNK = 200;

    private SymfonyStyle $io;
    private Randomizer $random;
    /** @var array<string, mixed> */
    private array $report = [];
    private FacetCounting $counting;

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogQuery $catalog,
        private readonly CategoryFacetResolver $facetResolver,
        private readonly FilterIndexRebuilder $rebuilder,
        private readonly FilterIndexer $indexer,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Повторів на сценарій (p50/p95)')] int $repeat = 20,
        #[Option(description: 'Seed вибору фільтрів і товарів')] int $seed = 1,
        #[Option(description: 'Товарів для інкрементальної переіндексації')] int $reindex = 1000,
        #[Option(description: 'Не перебудовувати індекс (лише запити)')] bool $skipRebuild = false,
        #[Option(description: 'Товарів у транзакції перебудови')] int $rebuildBatch = 5000,
        #[Option(description: 'Карток на сторінку')] int $perPage = 24,
        #[Option(description: 'JSON-звіт (відносно кореня проєкту)')] string $output = 'var/benchmark/catalog-benchmark.json',
        #[Option(description: 'Розділи через кому: listing,scaling,pagination,incremental')] string $sections = 'listing,scaling,pagination,incremental',
        #[Option(description: 'Категорії лістингу через кому: large,medium,small')] string $only = 'large,medium,small',
        #[Option(description: 'Лічильники фасетів: enabled (з порогом --max-set), disabled, exact')] string $facetCounts = 'enabled',
        #[Option(description: 'Поріг N карток для --facet-counts=enabled')] int $maxSet = FacetCountPolicy::DEFAULT_MAX_SET,
    ): int {
        $this->io = $io;
        $this->counting = match ($facetCounts) {
            'enabled' => new FacetCounting(true, $maxSet),
            'disabled' => new FacetCounting(false, $maxSet),
            'exact' => FacetCounting::exact(),
            default => throw new RuntimeException('--facet-counts: enabled, disabled або exact'),
        };
        $this->random = new Randomizer(new Mt19937($seed));
        if ($this->debug) {
            $io->warning('kernel.debug увімкнено: профайлер DBAL додає накладні витрати й пам\'ять — запускайте з --no-debug');
        }
        $repeat = max(1, $repeat);

        $this->environmentInfo($repeat, $seed);
        if (!$skipRebuild) {
            $io->section('Повна перебудова індексу');
            $stages = [];
            $result = $this->rebuilder->rebuild($rebuildBatch, static function (string $stage, array $data) use (&$stages): void {
                if ('indexes' === $stage) {
                    $stages += $data;
                }
            });
            $this->report['rebuild'] = $result + $stages + ['batch' => $rebuildBatch];
            if ([] !== $stages) {
                $io->writeln(\sprintf('заповнення %.1f с, вторинні індекси %.1f с', $stages['fill_seconds'], $stages['index_seconds']));
            }
            $io->writeln(\sprintf('зведення фасетів: %d категорій за %.1f с', $result['summaries'], $result['summary_seconds']));
            $io->writeln(\sprintf('%d товарів, %d варіантів, %d значень, %d товар×категорія — %.1f с', $result['products'], $result['variants'], $result['values'], $result['categories'], $result['seconds']));
        }
        $this->tableSizes();

        $categories = $this->pickCategories($perPage);
        $this->report['categories'] = $categories;
        $io->section('Категорії');
        $io->table(['роль', 'id', 'глибина', 'карток'], array_map(static fn (string $role, array $c): array => [$role, $c['id'], $c['depth'], $c['products']], array_keys($categories), $categories));

        $firstFilter = new CatalogFilter($categories['large']['id'], perPage: $perPage);
        $this->report['first_query_ms'] = $this->time(fn () => $this->catalog->execute($firstFilter, counting: $this->counting));
        $io->writeln(\sprintf('Перший запит великої категорії після перебудови (з фасетами): %.1f мс', $this->report['first_query_ms']));

        $enabled = array_map(trim(...), explode(',', $sections));
        if (\in_array('listing', $enabled, true)) {
            $this->listings(array_intersect_key($categories, array_flip(array_map(trim(...), explode(',', $only)))), $repeat, $perPage);
        }
        if (\in_array('scaling', $enabled, true)) {
            $this->scaling(min($repeat, 5), $perPage);
        }
        if (\in_array('pagination', $enabled, true)) {
            $this->pagination($categories['large']['id'], min($repeat, 10), $perPage);
        }
        if (\in_array('incremental', $enabled, true)) {
            $this->incremental($reindex, $repeat);
        }

        $path = str_starts_with($output, '/') ? $output : $this->projectDir.'/'.$output;
        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0o775, true) && !is_dir(\dirname($path))) {
            throw new RuntimeException('Не вдалося створити '.\dirname($path));
        }
        file_put_contents($path, json_encode($this->report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        $io->success('JSON: '.$path);

        return Command::SUCCESS;
    }

    private function environmentInfo(int $repeat, int $seed): void
    {
        $row = $this->db->fetchAssociative('SELECT @@version AS version, @@innodb_buffer_pool_size AS buffer_pool, @@innodb_redo_log_capacity AS redo, @@tmp_table_size AS tmp_table, @@sort_buffer_size AS sort_buffer, @@join_buffer_size AS join_buffer') ?: [];
        $counts = [];
        foreach (['catalog_product', 'catalog_product_variant', 'catalog_product_attribute_value', 'catalog_category'] as $table) {
            $counts[$table] = (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.$table);
        }
        $this->report['environment'] = [
            'php' => \PHP_VERSION, 'env' => $this->environment, 'debug' => $this->debug, 'repeat' => $repeat, 'seed' => $seed,
            'facet_counts' => ['enabled' => $this->counting->enabled, 'max_set' => $this->counting->maxSet],
            'mysql' => $row, 'counts' => $counts, 'started_at' => date('c'),
        ];
        $this->io->title('Benchmark каталогу');
        $this->io->definitionList(
            ['PHP' => \PHP_VERSION.' ('.$this->environment.($this->debug ? ', debug' : '').')'],
            ['MySQL' => \sprintf('%s, buffer pool %d МБ, tmp_table %d МБ', $row['version'] ?? '?', (int) ($row['buffer_pool'] ?? 0) / 1048576, (int) ($row['tmp_table'] ?? 0) / 1048576)],
            ['Каталог' => \sprintf('%d карток, %d варіантів, %d значень характеристик, %d категорій', ...array_values($counts))],
            ['Повторів' => $repeat],
            ['Лічильники фасетів' => $this->counting->enabled ? ($this->counting->maxSet < \PHP_INT_MAX ? 'увімкнені, N = '.$this->counting->maxSet : 'точні, без порогу') : 'вимкнені'],
        );
    }

    private function tableSizes(): void
    {
        $tables = [...FilterIndexTables::baseNames(), 'catalog_product', 'catalog_product_translation', 'catalog_product_variant', 'catalog_product_attribute_value', 'catalog_product_category', 'catalog_product_variant_axis_value'];
        $this->db->executeStatement('SET SESSION information_schema_stats_expiry = 0');
        foreach ($tables as $table) {
            $this->db->fetchAllAssociative('ANALYZE TABLE '.$table); // віддає result set — executeStatement лишив би його незчитаним
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT table_name AS t, table_rows AS approx_rows, data_length AS data, index_length AS idx FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN (?) ORDER BY data_length + index_length DESC',
            [$tables],
            [ArrayParameterType::STRING],
        );
        $sizes = [];
        foreach ($rows as $r) {
            $name = (string) $r['t'];
            $exact = \in_array($name, FilterIndexTables::baseNames(), true) ? (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.$name) : (int) $r['approx_rows'];
            $sizes[$name] = ['rows' => $exact, 'data_mb' => round((int) $r['data'] / 1048576, 1), 'index_mb' => round((int) $r['idx'] / 1048576, 1)];
        }
        $this->report['table_sizes'] = $sizes;
        $this->io->section('Розмір таблиць (рядки індексу — точні, решта — оцінка InnoDB)');
        $this->io->table(['таблиця', 'рядків', 'дані, МБ', 'індекси, МБ'], array_map(static fn (string $t, array $s): array => [$t, number_format($s['rows'], 0, '.', ' '), $s['data_mb'], $s['index_mb']], array_keys($sizes), $sizes));
    }

    /** @return array<string, array{id: int, depth: int, products: int}> */
    private function pickCategories(int $perPage): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT pc.category_id AS id, COUNT(*) AS products,
                    (SELECT COUNT(*) FROM catalog_category ch WHERE ch.parent_id = pc.category_id) AS children,
                    (CASE WHEN c.parent_id IS NULL THEN 0 WHEN p.parent_id IS NULL THEN 1 ELSE 2 END) AS depth
             FROM '.FilterIndexTables::PRODUCT_CATEGORY.' pc
             JOIN catalog_category c ON c.id = pc.category_id LEFT JOIN catalog_category p ON p.id = c.parent_id
             GROUP BY pc.category_id, c.parent_id, p.parent_id ORDER BY products DESC',
        );
        if ([] === $rows) {
            throw new RuntimeException('Індекс порожній: спершу catalog:fixtures:synthetic / catalog:filter-index:rebuild');
        }
        $pick = static fn (array $r): array => ['id' => (int) $r['id'], 'depth' => (int) $r['depth'], 'products' => (int) $r['products']];
        $leaves = array_values(array_filter($rows, static fn (array $r): bool => 0 === (int) $r['children']));
        $smallCandidates = array_values(array_filter($leaves, static fn (array $r): bool => (int) $r['products'] >= 2 * $perPage));
        $small = [] !== $smallCandidates ? end($smallCandidates) : end($rows);

        return ['large' => $pick($rows[0]), 'medium' => $pick([] !== $leaves ? $leaves[0] : $rows[0]), 'small' => $pick($small)];
    }

    /** @param array<string, array{id: int, depth: int, products: int}> $categories */
    private function listings(array $categories, int $repeat, int $perPage): void
    {
        $this->io->section(\sprintf('Лістинг категорії, мс (p50 / p95 з %d)', $repeat));
        $table = [];
        foreach ($categories as $role => $category) {
            $definitions = $this->facetResolver->load($category['id']);
            $baseline = $this->catalog->execute(new CatalogFilter($category['id'], perPage: $perPage), counting: FacetCounting::exact());
            foreach ([0, 1, 3] as $level) {
                $filters = [];
                for ($i = 0; $i < $repeat; ++$i) {
                    $filters[] = $this->filter($category['id'], $level, $definitions, $baseline, $perPage, $i);
                }
                foreach ([true, false] as $withFacets) {
                    $cold = array_map(fn (CatalogFilter $f): float => $this->time(fn () => $this->catalog->execute($f, $withFacets, counting: $this->counting)), $filters);
                    $fixed = $filters[0];
                    $repeatSql = [];
                    for ($i = 0; $i < $repeat; ++$i) {
                        $repeatSql[] = $this->time(fn () => $this->catalog->execute($fixed, $withFacets, counting: $this->counting));
                    }
                    $cached = null;
                    if ($withFacets) {
                        $this->catalog->search($fixed); // наповнити кеш
                        $cached = [];
                        for ($i = 0; $i < $repeat; ++$i) {
                            $cached[] = $this->time(fn () => $this->catalog->search($fixed));
                        }
                    }
                    $total = $this->catalog->execute($fixed, false)->total;
                    $entry = [
                        'category' => $role, 'category_id' => $category['id'], 'filters' => $level, 'facets' => $withFacets, 'result_total' => $total,
                        'uncached' => self::stats($cold), 'repeat_sql' => self::stats($repeatSql), 'cached' => null === $cached ? null : self::stats($cached),
                    ];
                    $this->report['listing'][] = $entry;
                    $table[] = [
                        $role.' ('.$category['products'].')', $level, $withFacets ? 'так' : 'ні', $total,
                        self::pair($entry['uncached']), self::pair($entry['repeat_sql']), null === $entry['cached'] ? '—' : \sprintf('%.2f / %.2f', $entry['cached']['p50'], $entry['cached']['p95']),
                    ];
                }
            }
        }
        $this->io->table(['категорія (карток)', 'фільтрів', 'фасети', 'знайдено', 'без кешу', 'повтор SQL', 'кеш'], $table);
    }

    /** Залежність від розміру: категорії, найближчі до 1k…40k карток, без фільтрів, з фасетами й без. */
    private function scaling(int $repeat, int $perPage): void
    {
        $rows = $this->db->fetchAllAssociative('SELECT category_id AS id, COUNT(*) AS products FROM '.FilterIndexTables::PRODUCT_CATEGORY.' GROUP BY category_id');
        $picked = [];
        foreach ([1_000, 2_500, 5_000, 10_000, 20_000, 40_000] as $target) {
            $best = null;
            foreach ($rows as $r) {
                if (null === $best || abs((int) $r['products'] - $target) < abs((int) $best['products'] - $target)) {
                    $best = $r;
                }
            }
            if (null !== $best && abs((int) $best['products'] - $target) <= $target / 2) {
                $picked[(int) $best['id']] = (int) $best['products'];
            }
        }
        asort($picked);
        $this->io->section(\sprintf('Залежність від розміру категорії (без фільтрів), мс p50 / p95 з %d', $repeat));
        $table = [];
        foreach ($picked as $id => $products) {
            $filter = new CatalogFilter($id, perPage: $perPage);
            $this->catalog->execute($filter, counting: $this->counting); // прогрів buffer pool цієї категорії
            $with = $without = [];
            for ($i = 0; $i < $repeat; ++$i) {
                $with[] = $this->time(fn () => $this->catalog->execute($filter, counting: $this->counting));
                $without[] = $this->time(fn () => $this->catalog->execute($filter, false));
            }
            $entry = ['category_id' => $id, 'products' => $products, 'facets' => self::stats($with), 'no_facets' => self::stats($without)];
            $this->report['scaling'][] = $entry;
            $table[] = [$id, $products, self::pair($entry['facets']), self::pair($entry['no_facets'])];
        }
        $this->io->table(['категорія', 'карток', 'з фасетами', 'без фасетів'], $table);
    }

    /**
     * 0 фільтрів — різні сортування/сторінки 1–3; 1 — одне значення select-характеристики (з топ-5 за лічильником);
     * 3 — select + значення осі варіанта (або другий select) + діапазон ціни (purchasable-рівень).
     *
     * @param list<FacetDefinition> $definitions
     */
    private function filter(int $categoryId, int $level, array $definitions, CatalogResult $baseline, int $perPage, int $i): CatalogFilter
    {
        $sorts = CatalogSort::cases();
        if (0 === $level) {
            return new CatalogFilter($categoryId, sort: $sorts[$i % \count($sorts)], page: 1 + intdiv($i, \count($sorts)) % 3, perPage: $perPage);
        }
        $selects = array_values(array_filter($definitions, static fn (FacetDefinition $d): bool => $d->hasOptions() && !$d->variantAxis && [] !== ($baseline->facets->options[$d->attributeId] ?? [])));
        $axes = array_values(array_filter($definitions, static fn (FacetDefinition $d): bool => $d->variantAxis && [] !== ($baseline->facets->options[$d->attributeId] ?? [])));
        if ([] === $selects) {
            return new CatalogFilter($categoryId, perPage: $perPage);
        }
        $first = $selects[$this->random->getInt(0, \count($selects) - 1)];
        $options = [$first->attributeId => [$this->popularOption($baseline, $first->attributeId)]];
        if (1 === $level) {
            return new CatalogFilter($categoryId, $options, perPage: $perPage);
        }
        $others = array_values(array_filter($selects, static fn (FacetDefinition $d): bool => $d->attributeId !== $first->attributeId));
        $second = $axes[0] ?? ([] !== $others ? $others[$this->random->getInt(0, \count($others) - 1)] : null);
        if (null !== $second) {
            $options[$second->attributeId] = [$this->popularOption($baseline, $second->attributeId)];
        }
        $price = null;
        if (null !== $baseline->facets->price) {
            $min = $baseline->facets->price->min;
            $span = max(1, $baseline->facets->price->max - $min);
            $from = $min + (int) ($span * $this->random->nextFloat() * 0.05);
            $price = new PriceRange($from, $from + (int) ($span * (0.1 + 0.3 * $this->random->nextFloat())));
        }

        return new CatalogFilter($categoryId, $options, price: $price, perPage: $perPage);
    }

    private function popularOption(CatalogResult $baseline, int $attributeId): int
    {
        $counts = $baseline->facets->options[$attributeId] ?? [];
        arsort($counts);
        $top = \array_slice(array_keys($counts), 0, 5);

        return [] === $top ? 0 : $top[$this->random->getInt(0, \count($top) - 1)];
    }

    /** OFFSET проти keyset на однакових позиціях: сторінка без COUNT і без фасетів, сортування за ціною. */
    private function pagination(int $categoryId, int $repeat, int $perPage): void
    {
        $this->io->section(\sprintf('Пагінація великої категорії, мс (p50 / p95 з %d; сторінка без COUNT і фасетів)', $repeat));
        $total = $this->catalog->execute(new CatalogFilter($categoryId, perPage: $perPage), false)->total;
        $pages = array_values(array_unique(array_filter([1, 2, 10, 100, 1000, (int) ceil($total / $perPage)], static fn (int $p): bool => $p >= 1 && ($p - 1) * $perPage < $total)));
        $table = [];
        foreach ($pages as $page) {
            $offsetFilter = new CatalogFilter($categoryId, page: $page, perPage: $perPage);
            $offset = [];
            for ($i = 0; $i < $repeat; ++$i) {
                $offset[] = $this->time(fn () => $this->catalog->execute($offsetFilter, false, false));
            }
            $keyset = null;
            $same = null;
            if ($page > 1) {
                $cursor = $this->catalog->execute(new CatalogFilter($categoryId, page: $page - 1, perPage: $perPage), false, false)->nextCursor;
                if (null !== $cursor) {
                    $keysetFilter = new CatalogFilter($categoryId, perPage: $perPage, after: $cursor);
                    $keyset = [];
                    for ($i = 0; $i < $repeat; ++$i) {
                        $keyset[] = $this->time(fn () => $this->catalog->execute($keysetFilter, false, false));
                    }
                    $same = $this->catalog->execute($keysetFilter, false, false)->productIds() === $this->catalog->execute($offsetFilter, false, false)->productIds();
                }
            }
            $entry = ['page' => $page, 'offset' => self::stats($offset), 'keyset' => null === $keyset ? null : self::stats($keyset), 'same_hits' => $same];
            $this->report['pagination'][] = $entry;
            $table[] = [$page, self::pair($entry['offset']), null === $entry['keyset'] ? '—' : self::pair($entry['keyset']), null === $same ? '—' : ($same ? 'так' : 'НІ')];
        }
        $this->io->table(['сторінка', 'OFFSET', 'keyset', 'ті самі картки'], $table);
    }

    private function incremental(int $count, int $repeat): void
    {
        $this->io->section('Інкрементальна переіндексація');
        $maxProduct = (int) $this->db->fetchOne('SELECT MAX(id) FROM catalog_product');
        $maxVariant = (int) $this->db->fetchOne('SELECT MAX(id) FROM catalog_product_variant');
        if ($maxProduct < 1 || $count < 1) {
            return;
        }
        $products = $variants = [];
        while (\count($products) < min($count, $maxProduct)) {
            $products[$this->random->getInt(1, $maxProduct)] = true;
        }
        while (\count($variants) < min($count, $maxVariant)) {
            $variants[$this->random->getInt(1, $maxVariant)] = true;
        }
        $products = array_keys($products);
        $variants = array_keys($variants);

        $chunks = [];
        $started = hrtime(true);
        foreach (array_chunk($products, self::REINDEX_CHUNK) as $chunk) {
            $chunks[] = $this->time(fn () => $this->indexer->reindexProducts($chunk));
        }
        $reindexTotal = (hrtime(true) - $started) / 1e6;

        $refreshChunks = [];
        $started = hrtime(true);
        foreach (array_chunk($variants, self::REINDEX_CHUNK) as $chunk) {
            $refreshChunks[] = $this->time(fn () => $this->indexer->refreshVariants($chunk));
        }
        $refreshTotal = (hrtime(true) - $started) / 1e6;

        $single = [];
        for ($i = 0; $i < $repeat; ++$i) {
            $id = $products[$i % \count($products)];
            $single[] = $this->time(fn () => $this->indexer->reindexProducts([$id]));
        }

        $this->report['incremental'] = [
            'reindex_products' => ['count' => \count($products), 'chunk' => self::REINDEX_CHUNK, 'total_ms' => round($reindexTotal, 1), 'chunk_ms' => self::stats($chunks)],
            'refresh_variants' => ['count' => \count($variants), 'chunk' => self::REINDEX_CHUNK, 'total_ms' => round($refreshTotal, 1), 'chunk_ms' => self::stats($refreshChunks)],
            'reindex_one_product_ms' => self::stats($single),
        ];
        $this->io->table(['операція', 'усього, мс', 'на пакет p50 / p95, мс'], [
            [\sprintf('reindexProducts: %d товарів пакетами по %d', \count($products), self::REINDEX_CHUNK), round($reindexTotal, 1), self::pair(self::stats($chunks))],
            [\sprintf('refreshVariants: %d варіантів пакетами по %d', \count($variants), self::REINDEX_CHUNK), round($refreshTotal, 1), self::pair(self::stats($refreshChunks))],
            ['reindexProducts: 1 товар', '—', self::pair(self::stats($single))],
        ]);
    }

    private function time(callable $work): float
    {
        $started = hrtime(true);
        $work();

        return (hrtime(true) - $started) / 1e6;
    }

    /**
     * @param list<float> $samples мс
     *
     * @return array{n: int, p50: float, p95: float, min: float, max: float, mean: float}
     */
    private static function stats(array $samples): array
    {
        sort($samples);
        $n = \count($samples);
        $rank = static fn (float $p): float => $samples[max(0, (int) ceil($p * $n) - 1)];

        return [
            'n' => $n, 'p50' => round($rank(0.5), 2), 'p95' => round($rank(0.95), 2),
            'min' => round($samples[0], 2), 'max' => round($samples[$n - 1], 2), 'mean' => round(array_sum($samples) / $n, 2),
        ];
    }

    /** @param array{p50: float, p95: float} $stats */
    private static function pair(array $stats): string
    {
        return \sprintf('%.1f / %.1f', $stats['p50'], $stats['p95']);
    }
}
