<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog\FilterIndex;

use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\FilterIndex\FilterIndexer;
use App\Core\Catalog\FilterIndex\FilterIndexRebuilder;
use App\Core\Catalog\FilterIndex\FilterIndexState;
use App\Core\Catalog\Query\CategorySummaryStore;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

/**
 * Повна перебудова (shadow + RENAME TABLE) — DDL у MySQL робить неявний COMMIT, тож без DAMA-відкату: дані фікстури
 * комітяться і прибираються в tearDown.
 */
#[SkipDatabaseRollback]
final class FilterIndexRebuilderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;
    private CatalogIndexFixture $fx;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = self::getContainer()->get(Connection::class);
        $this->fx = new CatalogIndexFixture($this->em, bin2hex(random_bytes(3)));
    }

    protected function tearDown(): void
    {
        $productIds = $this->fx->productIds();
        $this->em->clear();
        $this->db->executeStatement('DELETE FROM catalog_product WHERE id IN (?)', [$productIds], [ArrayParameterType::INTEGER]);
        foreach (['catalog_filter_variant', 'catalog_filter_value', 'catalog_filter_product_category'] as $table) {
            $this->db->executeStatement('DELETE FROM '.$table.' WHERE product_id IN (?)', [$productIds], [ArrayParameterType::INTEGER]);
        }
        $brandIds = [(int) $this->fx->nike->getId(), (int) $this->fx->puma->getId()];
        $this->db->executeStatement('DELETE FROM catalog_brand WHERE id IN (?)', [$brandIds], [ArrayParameterType::INTEGER]);
        foreach ([$this->fx->running, $this->fx->sneakers, $this->fx->shoes, $this->fx->bags] as $category) {
            $this->db->executeStatement('DELETE FROM catalog_category WHERE id = ?', [$category->getId()]);
            $this->db->executeStatement('DELETE FROM catalog_filter_category_summary WHERE category_id = ?', [$category->getId()]);
            $this->db->executeStatement('DELETE FROM catalog_filter_category_dirty WHERE category_id = ?', [$category->getId()]);
        }
        $attributeIds = array_map(static fn (Attribute $a): int => (int) $a->getId(), [$this->fx->size, $this->fx->color, $this->fx->material, $this->fx->weight, $this->fx->waterproof, $this->fx->hidden]);
        $this->db->executeStatement('DELETE FROM catalog_attribute WHERE id IN (?)', [$attributeIds], [ArrayParameterType::INTEGER]);
        parent::tearDown();
    }

    public function testRebuildSwapsTablesAndReplaysWritesMadeDuringIt(): void
    {
        $indexer = self::getContainer()->get(FilterIndexer::class);
        $p1 = $this->fx->id('P1');
        $v1 = (int) $this->fx->v['V1']->getId();
        $changed = false;

        $result = self::getContainer()->get(FilterIndexRebuilder::class)->rebuild(2, function (string $stage, array $data) use (&$changed, $p1, $v1, $indexer): void {
            // Щойно P1 потрапив у shadow — вебхук обнуляє залишок V1: запис іде в live і в dirty
            if (!$changed && 'batch' === $stage && $data['to'] >= $p1) {
                $this->db->executeStatement('UPDATE catalog_product_variant SET stock = 0 WHERE id = ?', [$v1]);
                $indexer->refreshVariants([$v1]);
                $changed = true;
            }
        });

        self::assertTrue($changed);
        self::assertGreaterThanOrEqual(1, $result['dirty_replayed']);
        self::assertSame(
            ['1', '0', '0'],
            array_values(array_map(strval(...), (array) $this->db->fetchAssociative('SELECT listed, purchasable, in_stock FROM catalog_filter_variant WHERE variant_id = ?', [$v1]))),
            'зміна під час перебудови не загубилась після RENAME',
        );
        self::assertSame(8, (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_filter_variant WHERE product_id IN (?)', [$this->fx->productIds()], [ArrayParameterType::INTEGER]));
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_filter_product_category WHERE product_id = ?', [$p1]));

        $state = self::getContainer()->get(FilterIndexState::class);
        self::assertFalse($state->isRebuilding());
        self::assertSame(0, $state->countDirty());
        self::assertNotNull($state->lastRebuiltAt());
        $leftovers = $this->db->fetchFirstColumn("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME LIKE 'catalog\\_filter\\_%\\_\\_shadow' OR TABLE_NAME LIKE 'catalog\\_filter\\_%\\_\\_old')");
        self::assertSame([], $leftovers);
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME LIKE 'catalog\\_filter\\_value\\_chk\\_%'"), 'CHECK пережив CREATE TABLE LIKE + RENAME');

        // ADR Rev 11: ключі сторінки в pc, гістограми на live і зведення категорій після swap
        self::assertSame(
            ['1', '900', '900'],
            array_values(array_map(strval(...), (array) $this->db->fetchAssociative('SELECT listed, sort_asc, sort_desc FROM catalog_filter_product_category WHERE category_id = ? AND product_id = ?', [$this->fx->shoes->getId(), $this->fx->id('P3')]))),
        );
        $histograms = $this->db->fetchFirstColumn("SELECT COLUMN_NAME FROM information_schema.COLUMN_STATISTICS WHERE SCHEMA_NAME = DATABASE() AND TABLE_NAME = 'catalog_filter_variant' ORDER BY COLUMN_NAME");
        self::assertSame(['in_stock', 'listed', 'price_mode', 'purchasable'], $histograms);
        self::assertGreaterThanOrEqual(4, $result['summaries']);
        $summary = self::getContainer()->get(CategorySummaryStore::class)->get((int) $this->fx->shoes->getId());
        self::assertNotNull($summary);
        self::assertSame(4, $summary->total, 'P1, P2, P3, P6');
    }

    public function testSecondRebuildIsRejectedWhileLockHeld(): void
    {
        $lock = self::getContainer()->get(LockFactory::class)->createLock(FilterIndexRebuilder::LOCK);
        self::assertTrue($lock->acquire());
        try {
            $this->expectException(RuntimeException::class);
            self::getContainer()->get(FilterIndexRebuilder::class)->rebuild();
        } finally {
            $lock->release();
        }
    }

    public function testCommand(): void
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('catalog:filter-index:rebuild'));
        $tester->execute(['--batch' => '1000']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Готово', $tester->getDisplay());
        self::assertSame(8, (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_filter_variant WHERE product_id IN (?)', [$this->fx->productIds()], [ArrayParameterType::INTEGER]));
    }
}
