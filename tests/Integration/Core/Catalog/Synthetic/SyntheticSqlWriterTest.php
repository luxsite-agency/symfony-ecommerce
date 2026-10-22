<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog\Synthetic;

use App\Core\Catalog\Enum\ImportRunStatus;
use App\Core\Catalog\Query\CatalogFilter;
use App\Core\Catalog\Query\CatalogQuery;
use App\Core\Catalog\Synthetic\SyntheticCatalogGenerator;
use App\Core\Catalog\Synthetic\SyntheticCatalogSpec;
use App\Core\Catalog\Synthetic\SyntheticSqlWriter;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Масовий запис синтетики (TRUNCATE і перебудова індексу — DDL з неявним COMMIT, тож без DAMA-відкату; каталог
 * тестової БД чиститься purge() до і після).
 */
#[SkipDatabaseRollback]
final class SyntheticSqlWriterTest extends KernelTestCase
{
    private Connection $db;
    private SyntheticSqlWriter $writer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $this->writer = self::getContainer()->get(SyntheticSqlWriter::class);
        $this->writer->purge();
    }

    protected function tearDown(): void
    {
        $this->writer->purge();
        parent::tearDown();
    }

    public function testWritesConsistentCatalogThatIndexesAndQueries(): void
    {
        $generator = new SyntheticCatalogGenerator(new SyntheticCatalogSpec(products: 240, maxVariants: 3, categories: 13, attributes: 6, brands: 10, seed: 5));
        $batches = [];
        $counts = $this->writer->write($generator, 100, static function (int $done) use (&$batches): void {
            $batches[] = $done;
        });
        self::assertSame([100, 200, 240], $batches);

        $locales = (array) self::getContainer()->getParameter('app.locales');
        self::assertSame(240, $counts['products']);
        self::assertSame(240, (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        self::assertSame(240 * \count($locales), (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_product_translation'));
        self::assertSame($counts['variants'], (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_product_variant'));
        self::assertSame($counts['attribute_rows'], (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_product_attribute_value'));
        self::assertSame($counts['axis_rows'], (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_product_variant_axis_value'));
        self::assertSame(13, (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_category'));
        self::assertSame(7, $counts['attributes'], '6 карткових + вісь');

        // посилальна цілісність, яку запис не перевіряв (FOREIGN_KEY_CHECKS = 0)
        foreach ([
            'SELECT COUNT(*) FROM catalog_product_variant v LEFT JOIN catalog_product p ON p.id = v.product_id WHERE p.id IS NULL',
            'SELECT COUNT(*) FROM catalog_product_attribute_value x LEFT JOIN catalog_attribute_value o ON o.id = x.option_id WHERE x.option_id IS NOT NULL AND (o.id IS NULL OR o.attribute_id <> x.attribute_id)',
            'SELECT COUNT(*) FROM catalog_product_variant_axis_value x JOIN catalog_attribute_value o ON o.id = x.attribute_value_id WHERE o.attribute_id <> x.attribute_id',
            'SELECT COUNT(*) FROM catalog_product_category pc LEFT JOIN catalog_category c ON c.id = pc.category_id WHERE c.id IS NULL',
            'SELECT COUNT(*) FROM catalog_product p LEFT JOIN catalog_brand b ON b.id = p.brand_id WHERE p.brand_id IS NOT NULL AND b.id IS NULL',
            'SELECT COUNT(*) FROM catalog_category c WHERE EXISTS (SELECT 1 FROM catalog_category x WHERE x.parent_id = c.id) AND EXISTS (SELECT 1 FROM catalog_product_category pc WHERE pc.category_id = c.id)',
        ] as $sql) {
            self::assertSame(0, (int) $this->db->fetchOne($sql), $sql);
        }

        try {
            $this->writer->write($generator);
            self::fail('другий запис у непорожній каталог');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('не порожня', $e->getMessage());
        }

        $tester = new CommandTester((new Application(self::$kernel))->find('catalog:filter-index:rebuild'));
        self::assertSame(0, $tester->execute([]));
        $root = (int) $this->db->fetchOne('SELECT id FROM catalog_category WHERE parent_id IS NULL ORDER BY id LIMIT 1');
        $result = self::getContainer()->get(CatalogQuery::class)->execute(new CatalogFilter($root));
        $expected = (int) $this->db->fetchOne(
            'SELECT COUNT(DISTINCT p.id) FROM catalog_product p JOIN catalog_product_category pc ON pc.product_id = p.id
             JOIN catalog_category c ON c.id = pc.category_id LEFT JOIN catalog_category g ON g.id = c.parent_id
             WHERE p.active = 1 AND (c.id = ? OR c.parent_id = ? OR g.parent_id = ?)',
            [$root, $root, $root],
        );
        self::assertSame($expected, $result->total, 'лістинг розділу = активні товари піддерева');
        self::assertNotEmpty($result->facets->options);
    }

    public function testCommandSqlAndImportModes(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('catalog:fixtures:synthetic'));
        self::assertSame(0, $tester->execute(['--products' => '50', '--categories' => '13', '--attributes' => '4', '--brands' => '5', '--batch' => '20']));
        self::assertStringContainsString('Індекс фільтрів', $tester->getDisplay());
        self::assertSame(50, (int) $this->db->fetchOne('SELECT COUNT(DISTINCT product_id) FROM catalog_filter_variant'));

        self::assertSame(1, $tester->execute(['--products' => '50']), 'непорожній каталог без --purge — помилка');

        self::assertSame(0, $tester->execute(['--mode' => 'import', '--purge' => true, '--products' => '30', '--categories' => '13', '--attributes' => '4', '--brands' => '5', '--batch' => '10']));
        self::assertSame(30, (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        self::assertSame(ImportRunStatus::Completed->value, $this->db->fetchOne("SELECT status FROM catalog_import_run WHERE source = 'synthetic'"));
        self::assertSame(2, $tester->execute(['--mode' => 'nope']));
    }
}
