<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Demo;

use App\Core\Catalog\Entity\Media;
use App\Core\Catalog\FilterIndex\FilterIndexRebuilder;
use App\Core\Content\SolutionLocales;
use App\Core\Pricing\Promotion\DoctrinePromotionProvider;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use RuntimeException;

/**
 * Видалення лише демо-даних (app:demo:seed --purge) за їхніми позначками (DemoCatalog, DemoAccounts). Не чіпає ніколи:
 * замовлення покупців (лише «DEMO #n:» з телефоном-заглушкою або демо-покупця), користувачів адмінки, налаштування. Спільні сутності
 * (категорія, характеристика, бренд, група покупців, рубрика, файл), якими вже користуються не-демо дані, лишаються
 * і потрапляють у звіт як «залишено».
 */
final class DemoPurger
{
    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        private readonly FilterIndexRebuilder $filterIndex,
        private readonly CacheManager $imagineCache,
        private readonly DoctrinePromotionProvider $promotions,
        private readonly DemoCustomers $customers,
        private readonly SolutionLocales $solutionLocales,
    ) {
    }

    /** @return array<string, int> що видалено/залишено */
    public function purge(): array
    {
        $sku = DemoCatalog::SKU_PREFIX.'%';
        $code = addcslashes(DemoCatalog::CODE_PREFIX, '_').'%';
        $slug = DemoCatalog::SLUG_PREFIX.'%';

        $report = $this->customers->purge(); // демо-покупці, їхні замовлення, кошики, компанія
        $report['orders'] = $this->db->executeStatement(
            'DELETE FROM sales_order WHERE comment LIKE ? AND phone LIKE ?',
            [DemoCatalog::ORDER_MARK.' #%', DemoCatalog::ORDER_PHONE_PREFIX.'__'],
        );

        // акції «DEMO: …» (цілі — каскадом FK); до товарів і категорій, щоб не лишилось акцій без цілей
        $report['promotions'] = $this->db->executeStatement(
            'DELETE FROM pricing_promotion WHERE name LIKE ?',
            [addcslashes(DemoCatalog::PROMOTION_PREFIX, '%_').'%'],
        );
        $this->promotions->reset();

        // демо-товар: усі варіанти з SKU DEMO-…
        $productIds = array_map('intval', $this->db->fetchFirstColumn(
            'SELECT p.id FROM catalog_product p WHERE EXISTS (SELECT 1 FROM catalog_product_variant v WHERE v.product_id = p.id AND v.sku LIKE ?)
             AND NOT EXISTS (SELECT 1 FROM catalog_product_variant v WHERE v.product_id = p.id AND v.sku NOT LIKE ?)',
            [$sku, $sku],
        ));
        if ([] !== $productIds) {
            $foreign = (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM catalog_option_value ov JOIN catalog_option_group g ON g.id = ov.group_id
                 JOIN catalog_product_variant v ON v.id = ov.component_variant_id
                 WHERE v.product_id IN (?) AND g.product_id NOT IN (?)',
                [$productIds, $productIds],
                [ArrayParameterType::INTEGER, ArrayParameterType::INTEGER],
            );
            if ($foreign > 0) {
                throw new RuntimeException(\sprintf('Не-демо товари мають %d опцій-компонентів з демо-товарів: приберіть їх в адмінці й повторіть --purge', $foreign));
            }
            $params = [$productIds];
            $types = [ArrayParameterType::INTEGER];
            // спершу опції (компонент — RESTRICT на варіант іншого демо-товару), далі товари; решта — каскадом FK
            $this->db->executeStatement('DELETE FROM catalog_option_group WHERE product_id IN (?)', $params, $types);
            $report['products'] = $this->db->executeStatement('DELETE FROM catalog_product WHERE id IN (?)', $params, $types);
        }

        $report += $this->purgeCategories($slug);

        [$report['attributes'], $report['attributes_kept']] = $this->deleteUnused(
            'SELECT id FROM catalog_attribute WHERE code LIKE ?',
            [$code],
            'SELECT COUNT(*) FROM catalog_product_attribute_value WHERE attribute_id = ?
             UNION ALL SELECT COUNT(*) FROM catalog_product_variant_axis_value WHERE attribute_id = ?',
            'DELETE FROM catalog_attribute WHERE id = ?',
        );
        [$report['brands'], $report['brands_kept']] = $this->deleteUnused(
            'SELECT id FROM catalog_brand WHERE slug LIKE ?',
            [$slug],
            'SELECT COUNT(*) FROM catalog_product WHERE brand_id = ?',
            'DELETE FROM catalog_brand WHERE id = ?',
        );
        [$report['customer_groups'], $report['customer_groups_kept']] = $this->deleteUnused(
            'SELECT id FROM customer_group WHERE code = ?',
            [DemoCatalog::CUSTOMER_GROUP],
            'SELECT COUNT(*) FROM catalog_price_tier WHERE customer_group_id = ?',
            'DELETE FROM customer_group WHERE id = ?',
        );

        $report['pages'] = $this->db->executeStatement(
            'DELETE FROM content_page WHERE id IN (SELECT page_id FROM (SELECT DISTINCT page_id FROM content_page_translation WHERE slug LIKE ?) t)',
            [$slug],
        );
        $report['solutions'] = $this->purgeSolutions();
        $report['posts'] = $this->db->executeStatement(
            'DELETE FROM content_post WHERE id IN (SELECT post_id FROM (SELECT DISTINCT post_id FROM content_post_translation WHERE slug LIKE ?) t)',
            [$slug],
        );
        [$report['post_categories'], $report['post_categories_kept']] = $this->deleteUnused(
            'SELECT id FROM content_post_category WHERE code LIKE ?',
            [$code],
            'SELECT COUNT(*) FROM content_post WHERE category_id = ?',
            'DELETE FROM content_post_category WHERE id = ?',
        );

        [$report['media'], $report['media_kept']] = $this->purgeMedia();

        $this->em->clear();
        $this->filterIndex->rebuild();

        return array_filter($report, static fn (int $n): bool => $n > 0);
    }

    /** Демо-рішення (слаг demo-…); сирий SQL повз ORM — кеш мов з рішеннями (пункт меню) скидаємо самі (рев'ю #102, M1). */
    public function purgeSolutions(): int
    {
        $deleted = $this->db->executeStatement(
            'DELETE FROM content_solution WHERE id IN (SELECT solution_id FROM (SELECT DISTINCT solution_id FROM content_solution_translation WHERE slug LIKE ?) t)',
            [DemoCatalog::SLUG_PREFIX.'%'],
        );
        $this->solutionLocales->forget();

        return $deleted;
    }

    /**
     * Категорії зі слагом demo-… будь-якою мовою, знизу вгору (parent — RESTRICT). Лишається категорія з не-демо
     * товарами або не-демо підкатегоріями — і всі її предки.
     *
     * @return array{categories: int, categories_kept: int}
     */
    private function purgeCategories(string $slug): array
    {
        $ids = array_map('intval', $this->db->fetchFirstColumn('SELECT DISTINCT category_id FROM catalog_category_translation WHERE slug LIKE ?', [$slug]));
        $deleted = 0;
        do {
            $round = 0;
            foreach ($ids as $i => $id) {
                $busy = (int) $this->db->fetchOne(
                    'SELECT (SELECT COUNT(*) FROM catalog_category WHERE parent_id = ?) + (SELECT COUNT(*) FROM catalog_product_category WHERE category_id = ?)
                     + (SELECT COUNT(*) FROM catalog_product WHERE main_category_id = ?)',
                    [$id, $id, $id],
                );
                if (0 !== $busy) {
                    continue;
                }
                foreach (['catalog_filter_category_summary', 'catalog_filter_category_dirty'] as $table) {
                    $this->db->executeStatement('DELETE FROM '.$table.' WHERE category_id = ?', [$id]);
                }
                $this->db->executeStatement('DELETE FROM catalog_category WHERE id = ?', [$id]);
                unset($ids[$i]);
                ++$round;
            }
            $deleted += $round;
        } while ($round > 0);

        return ['categories' => $deleted, 'categories_kept' => \count($ids)];
    }

    /**
     * @param list<string> $params
     *
     * @return array{int, int} видалено, залишено (використовуються не-демо даними)
     */
    private function deleteUnused(string $select, array $params, string $usage, string $delete): array
    {
        $deleted = $kept = 0;
        foreach (array_map('intval', $this->db->fetchFirstColumn($select, $params)) as $id) {
            $placeholders = substr_count($usage, '?');
            $used = array_sum(array_map('intval', $this->db->fetchFirstColumn($usage, array_fill(0, $placeholders, $id))));
            if ($used > 0) {
                ++$kept;
                continue;
            }
            $this->db->executeStatement($delete, [$id]);
            ++$deleted;
        }

        return [$deleted, $kept];
    }

    /**
     * Файли demo-…, на які вже ніхто не посилається: через ORM, щоб Vich прибрав файл, плюс мініатюри Liip.
     *
     * @return array{int, int}
     */
    private function purgeMedia(): array
    {
        $this->em->clear();
        $deleted = $kept = 0;
        /** @var list<Media> $candidates */
        $candidates = $this->em->createQueryBuilder()->select('m')->from(Media::class, 'm')
            ->where('m.path LIKE :prefix')->setParameter('prefix', DemoCatalog::SLUG_PREFIX.'%')->getQuery()->getResult();
        foreach ($candidates as $media) {
            $used = (int) $this->db->fetchOne(
                'SELECT (SELECT COUNT(*) FROM catalog_product_media WHERE media_id = ?) + (SELECT COUNT(*) FROM content_post WHERE cover_id = ?) + (SELECT COUNT(*) FROM catalog_category WHERE image_id = ?)',
                [$media->getId(), $media->getId(), $media->getId()],
            );
            if ($used > 0) {
                ++$kept;
                continue;
            }
            $this->imagineCache->remove(['media/'.$media->getPath(), '/media/'.$media->getPath()]);
            $this->em->remove($media);
            ++$deleted;
        }
        $this->em->flush();

        return [$deleted, $kept];
    }
}
