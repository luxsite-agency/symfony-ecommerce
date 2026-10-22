<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Url;

use App\Core\Catalog\Entity\CategoryTranslation;
use App\Core\Catalog\Url\Event\CategoryPathsChanged;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Повний шлях категорії за деревом для кожної мови (CategoryTranslation.path: «odyah/futbolky/chorni»): батьківський
 * шлях + слаг, корінь — слаг. Нема слага цією мовою або батько без шляху — шлях null (категорія без адреси цією мовою).
 *
 * Перерахунок — SQL без ORM, вузли за глибиною. Спершу змінені шляхи обнуляються, потім пишуться нові: обмін шляхами
 * між сестрами не впирається в UNIQUE(locale, path). Справжній конфлікт із вузлом поза перерахунком — шлях null і
 * попередження в лог (валідатор адмінки й генератор слагів такого не допускають; лишається гонка).
 * У транзакції запису — подія CategoryPathsChanged зі старими й новими шляхами (авто-301 атомарно з UPDATE).
 */
final class CategoryPaths
{
    public const int MAX_LENGTH = 700;

    /** @var array<int, string|null> id перекладу → шлях, записані останнім rebuild() (для syncManaged) */
    private array $written = [];

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /**
     * Перенести записані SQL-ом шляхи в уже завантажені сутності (identity map), не роблячи їх «брудними»: інакше
     * шаблон у тому самому запиті (адмінка після збереження, тести) бачить старий path.
     */
    public function syncManaged(EntityManagerInterface $em): void
    {
        if ([] === $this->written) {
            return;
        }
        $uow = $em->getUnitOfWork();
        foreach ($uow->getIdentityMap()[CategoryTranslation::class] ?? [] as $translation) {
            if ($translation instanceof CategoryTranslation && \array_key_exists((int) $translation->getId(), $this->written)) {
                $path = $this->written[(int) $translation->getId()];
                $translation->syncPath($path);
                $uow->setOriginalEntityProperty(spl_object_id($translation), 'path', $path);
            }
        }
        $this->written = [];
    }

    /** Усі категорії (міграція, команда після масових змін). */
    public function rebuildAll(): int
    {
        /** @var list<int|string> $roots */
        $roots = $this->db->fetchFirstColumn('SELECT id FROM catalog_category WHERE parent_id IS NULL');

        return $this->rebuild(array_map(intval(...), $roots));
    }

    /**
     * @param list<int> $categoryIds вузли; $withDescendants — разом з піддеревами
     *
     * @return int змінених рядків перекладів
     */
    public function rebuild(array $categoryIds, bool $withDescendants = true): int
    {
        if ([] === $categoryIds) {
            return 0;
        }
        $nodes = $withDescendants
            ? $this->db->fetchAllAssociative(
                'WITH RECURSIVE sub AS (
                   SELECT id, parent_id, 0 AS depth FROM catalog_category WHERE id IN (?)
                   UNION ALL
                   SELECT c.id, c.parent_id, sub.depth + 1 FROM catalog_category c JOIN sub ON c.parent_id = sub.id
                 ) SELECT id, parent_id, MIN(depth) AS depth FROM sub GROUP BY id, parent_id ORDER BY depth',
                [$categoryIds],
                [ArrayParameterType::INTEGER],
            )
            : $this->db->fetchAllAssociative('SELECT id, parent_id FROM catalog_category WHERE id IN (?)', [$categoryIds], [ArrayParameterType::INTEGER]);
        if ([] === $nodes) {
            return 0;
        }
        $ids = array_map(static fn (array $n): int => (int) $n['id'], $nodes);
        $parents = [];
        foreach ($nodes as $n) {
            $parents[(int) $n['id']] = null === $n['parent_id'] ? null : (int) $n['parent_id'];
        }

        // переклади вузлів і (для батьків поза набором) їхні поточні шляхи
        $translations = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, category_id, locale, slug, path FROM catalog_category_translation WHERE category_id IN (?)', [$ids], [ArrayParameterType::INTEGER]) as $t) {
            $translations[(int) $t['category_id']][(string) $t['locale']] = $t;
        }
        $outside = array_values(array_diff(array_filter($parents, static fn (?int $p): bool => null !== $p), $ids));
        $known = []; // category_id → locale → path
        if ([] !== $outside) {
            foreach ($this->db->fetchAllAssociative('SELECT category_id, locale, path FROM catalog_category_translation WHERE category_id IN (?)', [$outside], [ArrayParameterType::INTEGER]) as $t) {
                $known[(int) $t['category_id']][(string) $t['locale']] = null === $t['path'] ? null : (string) $t['path'];
            }
        }

        // батьки раніше дітей: вузли з набору сортуємо топологічно
        $ordered = $this->topological($ids, $parents);
        $changes = []; // translation id → новий шлях
        $previous = []; // translation id → [мова, старий шлях] — для CategoryPathsChanged
        foreach ($ordered as $id) {
            foreach ($translations[$id] ?? [] as $locale => $t) {
                $slug = null === $t['slug'] ? null : (string) $t['slug'];
                $parent = $parents[$id];
                $parentPath = null === $parent ? '' : ($known[$parent][$locale] ?? null);
                $path = null === $slug || null === $parentPath ? null : ('' === $parentPath ? $slug : $parentPath.'/'.$slug);
                if (null !== $path && \strlen($path) > self::MAX_LENGTH) {
                    $path = null;
                }
                $known[$id][$locale] = $path;
                if ($path !== (null === $t['path'] ? null : (string) $t['path'])) {
                    $changes[(int) $t['id']] = $path;
                    $previous[(int) $t['id']] = [$locale, null === $t['path'] ? null : (string) $t['path']];
                }
            }
        }
        if ([] === $changes) {
            return 0;
        }

        $written = &$this->written;
        $this->db->transactional(function () use ($changes, &$written, $previous): void {
            $moved = [];
            $this->db->executeStatement('UPDATE catalog_category_translation SET path = NULL WHERE id IN (?)', [array_keys($changes)], [ArrayParameterType::INTEGER]);
            foreach ($changes as $translationId => $path) {
                $written[$translationId] = null;
                if (null === $path) {
                    continue;
                }
                $row = $this->db->fetchAssociative('SELECT locale FROM catalog_category_translation WHERE id = ?', [$translationId]);
                $clash = false === $row ? false : $this->db->fetchOne('SELECT category_id FROM catalog_category_translation WHERE locale = ? AND path = ?', [$row['locale'], $path]);
                if (false !== $clash) {
                    $this->logger->warning('Шлях категорії зайнятий — категорія лишається без адреси цією мовою', ['translation' => $translationId, 'path' => $path, 'holder' => $clash]);
                    continue;
                }
                $this->db->executeStatement('UPDATE catalog_category_translation SET path = ? WHERE id = ?', [$path, $translationId]);
                $written[$translationId] = $path;
                [$locale, $old] = $previous[$translationId];
                $moved[] = ['locale' => (string) $locale, 'old' => $old, 'new' => $path];
            }
            // у тій самій транзакції: авто-301 пишуться атомарно з новими шляхами
            if ([] !== $moved) {
                $this->events->dispatch(new CategoryPathsChanged($moved));
            }
        });

        return \count($changes);
    }

    /**
     * @param list<int>            $ids
     * @param array<int, int|null> $parents
     *
     * @return list<int>
     */
    private function topological(array $ids, array $parents): array
    {
        $in = array_flip($ids);
        $depth = [];
        $depthOf = static function (int $id) use (&$depthOf, &$depth, $parents, $in): int {
            if (isset($depth[$id])) {
                return $depth[$id];
            }
            $parent = $parents[$id] ?? null;
            $depth[$id] = -1; // захист від циклу
            $depth[$id] = null !== $parent && isset($in[$parent]) ? $depthOf($parent) + 1 : 0;

            return $depth[$id];
        };
        foreach ($ids as $id) {
            $depthOf($id);
        }
        usort($ids, static fn (int $a, int $b): int => $depth[$a] <=> $depth[$b]);

        return $ids;
    }
}
