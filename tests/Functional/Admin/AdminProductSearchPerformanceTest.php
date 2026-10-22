<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Admin\Search\AdminSearchQuery;
use App\Admin\Search\SmartSearchRepository;
use App\Core\Catalog\Entity\Product;
use App\Tests\Support\CommittedCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Швидкість пошуку товарів адмінки на 10 000 карток (дві мови, 50 брендів): умови розумного пошуку + підрахунок + перша
 * сторінка списку — менше 300 мс на запит (рішення власника 20.10.2026). Каталог комітиться: FULLTEXT не бачить незакомічених.
 */
final class AdminProductSearchPerformanceTest extends KernelTestCase
{
    private const int PRODUCTS = 10_000;
    private const int BRANDS = 50;
    private const float BUDGET_MS = 300.0;
    private const array WORDS = ['aspirin', 'paracetamol', 'ibuprofen', 'vitamin', 'magnesium', 'zinc', 'omega', 'probiotic', 'cream', 'spray',
        'tablets', 'capsules', 'syrup', 'drops', 'gel', 'forte', 'junior', 'plus', 'max', 'rapid'];

    protected function tearDown(): void
    {
        CommittedCatalog::purge(static::getContainer()->get(EntityManagerInterface::class)->getConnection(), range(CommittedCatalog::BASE_ID + 1, CommittedCatalog::BASE_ID + self::BRANDS));
        parent::tearDown();
    }

    public function testTenThousandProductsAreSearchedUnderBudget(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $brands = [];
        for ($b = 1; $b <= self::BRANDS; ++$b) {
            $brands[CommittedCatalog::BASE_ID + $b] = 'Brandname'.$b;
        }
        $products = [];
        for ($i = 1; $i <= self::PRODUCTS; ++$i) {
            $w = self::WORDS;
            $name = $w[$i % 20].' '.$w[($i * 7) % 20].' '.(100 + $i % 900);
            $products[CommittedCatalog::BASE_ID + $i] = [
                'names' => ['uk' => 'ук '.$name, 'en' => $name],
                'sku' => \sprintf('PERF-%05d', $i),
                'barcode' => \sprintf('48%011d', $i),
                'brand' => CommittedCatalog::BASE_ID + 1 + $i % self::BRANDS,
            ];
        }
        CommittedCatalog::insert($em->getConnection(), $products, $brands);

        $search = static::getContainer()->get(SmartSearchRepository::class)->searcher(Product::class);
        self::assertNotNull($search);
        $queries = ['aspirin', 'vitamin paracetamol', 'perf-0042', '480000000123', 'brandname7', 'aspirin brandname1 500', 'zi', 'nothingatall'];
        $search->apply($em->createQueryBuilder()->select('entity')->from(Product::class, 'entity'), ['warmup']);
        foreach ($queries as $query) {
            $started = hrtime(true);
            $qb = $em->createQueryBuilder()->select('entity')->from(Product::class, 'entity')->orderBy('entity.id', 'DESC');
            $search->apply($qb, AdminSearchQuery::terms($query));
            $count = (int) (clone $qb)->select('COUNT(DISTINCT entity)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
            $page = $qb->setMaxResults(20)->getQuery()->getResult();
            $ms = (hrtime(true) - $started) / 1e6;
            $em->clear();
            self::assertLessThan(self::BUDGET_MS, $ms, \sprintf('«%s»: %.0f мс, знайдено %d', $query, $ms, $count));
            if ('nothingatall' !== $query) {
                self::assertGreaterThan(0, $count, $query);
                self::assertNotEmpty($page, $query);
            }
        }
    }
}
