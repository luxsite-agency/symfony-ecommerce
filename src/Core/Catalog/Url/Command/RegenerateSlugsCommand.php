<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Url\Command;

use App\Core\Catalog\Query\CatalogCacheVersion;
use App\Core\Catalog\Url\CatalogSlugs;
use App\Core\Catalog\Url\CategoryPaths;
use App\Core\Seo\SlugChange;
use App\Core\Seo\SlugChangeRecorder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Перегенерувати слаги товарів і категорій новим слагером (seo-tech-notes п. 12: без дефіса на мʼякому знаку). Стара
 * адреса веде 301 на нову (E3): товари — SlugChangeRecorder у транзакції пакета разом з UPDATE слагів, категорії —
 * CategoryPaths::rebuildAll() (CategoryPathsChanged у транзакції запису шляхів). Лише автоматичні слаги: поточний = старий слагер із назви (з суфіксом -N чи без),
 * ручні не чіпаються (--all — чіпати й їх). Пропускаються й ті, на чиї адреси посилаються сторінки, записи блогу або
 * описи товарів і категорій (посилання в тексті зламались би). Спершу --dry-run.
 *
 * Запис — лише коли сайт закритий від індексу: команда перевіряє X-Robots-Tag головної (HEAD https://site_host/); без
 * noindex або без відповіді — відмова (--allow-indexed — свідомо: старі адреси з індексу ведуть 301 на нові). Переклади
 * читаються пакетами за id (пам'ять стала на 500k×мови), кожен пакет пишеться однією транзакцією разом з updated_at
 * товару (lastmod у sitemap); наприкінці — шляхи категорій і нова версія кешу каталогу (резолвер URL).
 */
#[AsCommand(name: 'catalog:slugs:regenerate', description: 'Перегенерувати автоматичні слаги товарів і категорій новим слагером (старі адреси — 301 на нові)')]
final class RegenerateSlugsCommand
{
    /** @var array<string, true>|null шляхи, на які посилаються тексти (collectLinks), — один раз на запуск */
    private ?array $linked = null;

    private const array TABLES = ['category' => ['catalog_category_translation', 'category_id'], 'product' => ['catalog_product_translation', 'product_id']];

    public function __construct(
        private readonly Connection $db,
        #[Target('urlSlugger')] private readonly SluggerInterface $slugger,
        private readonly CatalogSlugs $slugs,
        private readonly CategoryPaths $paths,
        private readonly CatalogCacheVersion $cacheVersion,
        private readonly SlugChangeRecorder $redirects,
        private readonly HttpClientInterface $http,
        #[Autowire('%site_host%')] private readonly string $siteHost,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
        /** @var list<string> */
        #[Autowire('%app.locales%')] private readonly array $locales,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Лише показати, що зміниться')] bool $dryRun = false,
        #[Option(description: 'Разом із ручними слагами (не збігаються зі старим автоматичним)')] bool $all = false,
        #[Option(description: 'Писати, навіть якщо сайт відкритий для індексу (старі адреси ведуть 301 на нові)')] bool $allowIndexed = false,
        #[Option(description: 'Перекладів в одному пакеті')] int $batch = 1000,
    ): int {
        if (!$dryRun && !$allowIndexed && null !== ($reason = $this->indexable())) {
            $io->error(\sprintf('Сайт %s не закритий від індексу (%s): старі адреси в пошуку отримають 301 на нові. Спершу --dry-run; якщо це свідомо — --allow-indexed.', $this->siteHost, $reason));

            return Command::FAILURE;
        }
        $this->linked = null;
        $this->slugs->reset(); // слаги, «видані» попереднім запуском у цьому процесі (dry-run), — не зайняті
        $old = new AsciiSlugger();
        $batch = max(1, $batch);
        $rows = [];
        $changed = 0;
        foreach (self::TABLES as $type => [$table, $owner]) {
            for ($after = 0; [] !== ($chunk = $this->chunk($table, $owner, $after, $batch)); $after = (int) end($chunk)['id']) {
                $updates = [];
                foreach ($chunk as $t) {
                    $slug = (string) $t['slug'];
                    $locale = (string) $t['locale'];
                    $new = mb_substr($this->slugger->slug((string) $t['name'], '-', $locale)->lower()->toString(), 0, 180);
                    if ('' === $new || $slug === $new || 1 === preg_match('/^'.preg_quote($new, '/').'-\d+$/', $slug)) {
                        continue; // уже новий
                    }
                    $auto = false;
                    foreach (array_unique([$locale, $this->defaultLocale]) as $l) { // імпорт слагував мовою магазину за замовчуванням
                        $was = $old->slug((string) $t['name'], '-', $l)->lower()->toString();
                        $auto = $auto || $slug === $was || 1 === preg_match('/^'.preg_quote($was, '/').'-\d+$/', $slug);
                    }
                    if (!$auto && !$all) {
                        $rows[] = [$type, $locale, $slug, $new, 'ручний слаг — пропущено'];
                        continue;
                    }
                    $url = 'category' === $type ? $this->categoryPath((int) $t['owner'], $locale) : $slug;
                    if (null !== $url && $this->linkedFromContent($url)) {
                        $rows[] = [$type, $locale, $slug, $new, 'є посилання в текстах — пропущено'];
                        continue;
                    }
                    if ('product' === $type) {
                        $new = $this->slugs->uniqueProductSlug($new, $locale, (int) $t['owner']);
                    } else {
                        $new = $this->slugs->uniqueCategorySlug($new, $locale, $this->parentPath((int) $t['owner'], $locale), (int) $t['owner']);
                    }
                    $rows[] = [$type, $locale, $slug, $new, $dryRun ? 'буде змінено' : 'змінено'];
                    $updates[] = [(int) $t['id'], (int) $t['owner'], $new, $locale, $slug];
                }
                if (!$dryRun && [] !== $updates) {
                    $this->db->transactional(function (Connection $db) use ($table, $type, $updates): void {
                        foreach ($updates as [$id, , $new]) {
                            $db->executeStatement("UPDATE {$table} SET slug = ? WHERE id = ?", [$new, $id]);
                        }
                        if ('product' === $type) {
                            $db->executeStatement('UPDATE catalog_product SET updated_at = NOW() WHERE id IN (?)', [array_values(array_unique(array_column($updates, 1)))], [ArrayParameterType::INTEGER]);
                            // 301 старий → новий у тій самій транзакції; категорії — rebuildAll() нижче (адреса = шлях)
                            $this->redirects->record(array_map(static fn (array $u): SlugChange => new SlugChange(SlugChange::PRODUCT, $u[3], $u[4], $u[2]), $updates));
                        }
                    });
                    $changed += \count($updates);
                }
            }
        }
        if (!$dryRun && $changed > 0) {
            $this->paths->rebuildAll();
            $this->cacheVersion->bump();
        }
        if ([] !== $rows) {
            $io->table(['що', 'мова', 'було', 'стане', 'дія'], $rows);
        }
        $io->success($dryRun ? \sprintf('Сухий прогін: змін %d', \count(array_filter($rows, static fn (array $r): bool => 'буде змінено' === $r[4]))) : \sprintf('Змінено слагів: %d', $changed));

        return Command::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function chunk(string $table, string $owner, int $after, int $limit): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, {$owner} AS owner, locale, name, slug FROM {$table} WHERE id > ? AND slug IS NOT NULL AND name <> '' ORDER BY id LIMIT ?",
            [$after, $limit],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    /** null — сайт закритий від індексу (X-Robots-Tag: noindex); інакше причина, чому вважаємо відкритим. */
    private function indexable(): ?string
    {
        try {
            $response = $this->http->request('HEAD', 'https://'.$this->siteHost.'/', ['timeout' => 10, 'max_redirects' => 3]);
            $tags = $response->getHeaders(false)['x-robots-tag'] ?? [];
        } catch (ExceptionInterface $e) {
            return 'головна не відповіла: '.$e->getMessage();
        }
        foreach ($tags as $tag) {
            if (str_contains(strtolower($tag), 'noindex')) {
                return null;
            }
        }

        return [] === $tags ? 'нема X-Robots-Tag' : 'X-Robots-Tag: '.implode(', ', $tags);
    }

    private function categoryPath(int $categoryId, string $locale): ?string
    {
        $path = $this->db->fetchOne('SELECT path FROM catalog_category_translation WHERE category_id = ? AND locale = ?', [$categoryId, $locale]);

        return false === $path || null === $path ? null : (string) $path;
    }

    private function parentPath(int $categoryId, string $locale): ?string
    {
        $parent = $this->db->fetchOne('SELECT parent_id FROM catalog_category WHERE id = ?', [$categoryId]);

        return null === $parent || false === $parent ? null : $this->categoryPath((int) $parent, $locale);
    }

    /** Адреса (шлях товару чи категорії) — сама або як початок шляху — є серед посилань у текстах. */
    private function linkedFromContent(string $url): bool
    {
        $this->linked ??= $this->collectLinks();

        return isset($this->linked[trim($url, '/')]);
    }

    /**
     * Один прохід пакетами за id по текстах (сторінки, записи з «Читайте також», описи товарів і категорій): шляхи з
     * href="…" і "url": "…" без хоста, мовного префікса й параметрів, разом з усіма префіксами (посилання на
     * підкатегорію чи товар у ній ламається і від зміни слага батьківської категорії). LIKE на кожен слаг — повний скан
     * 500k описів на кожну зміну.
     *
     * @return array<string, true>
     */
    private function collectLinks(): array
    {
        $links = [];
        $sources = [
            'content_page_translation' => ['body'],
            'content_post_translation' => ['body', 'related'],
            'catalog_product_translation' => ['description', 'short_description'],
            'catalog_category_translation' => ['description'],
        ];
        foreach ($sources as $table => $columns) {
            $select = implode(', ', $columns);
            $where = implode(' OR ', array_map(static fn (string $c): string => "{$c} LIKE '%/%'", $columns));
            for ($after = 0; [] !== ($rows = $this->db->fetchAllAssociative("SELECT id, {$select} FROM {$table} WHERE id > ? AND ({$where}) ORDER BY id LIMIT 2000", [$after], [ParameterType::INTEGER])); $after = (int) end($rows)['id']) {
                foreach ($rows as $row) {
                    foreach ($columns as $column) {
                        preg_match_all('~(?:href\s*=\s*["\']?|"url"\s*:\s*")([^"\'\s>]+)~i', (string) $row[$column], $m);
                        foreach ($m[1] as $href) {
                            $this->addLink($links, $href);
                        }
                    }
                }
            }
        }

        return $links;
    }

    /** @param array<string, true> $links */
    private function addLink(array &$links, string $href): void
    {
        $path = parse_url(html_entity_decode($href, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), \PHP_URL_PATH);
        if (!\is_string($path)) {
            return;
        }
        $segments = array_values(array_filter(explode('/', rawurldecode($path)), static fn (string $s): bool => '' !== $s));
        if ([] !== $segments && \in_array($segments[0], $this->locales, true)) {
            array_shift($segments);
        }
        for ($i = 1, $n = \count($segments); $i <= $n; ++$i) {
            $links[implode('/', \array_slice($segments, 0, $i))] = true;
        }
    }
}
