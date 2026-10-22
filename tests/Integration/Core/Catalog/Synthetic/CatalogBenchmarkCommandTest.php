<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog\Synthetic;

use App\Core\Catalog\Synthetic\SyntheticSqlWriter;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Smoke benchmark на крихітній синтетиці: усі розділи звіту заповнені, keyset дає ті самі картки, що й OFFSET. */
#[SkipDatabaseRollback]
final class CatalogBenchmarkCommandTest extends KernelTestCase
{
    private SyntheticSqlWriter $writer;
    private string $output;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->writer = self::getContainer()->get(SyntheticSqlWriter::class);
        $this->writer->purge();
        $this->output = sys_get_temp_dir().'/catalog-benchmark-'.bin2hex(random_bytes(3)).'.json';
    }

    protected function tearDown(): void
    {
        $this->writer->purge();
        @unlink($this->output);
        parent::tearDown();
    }

    public function testBenchmarkProducesFullReport(): void
    {
        $application = new Application(self::$kernel);
        $fixtures = new CommandTester($application->find('catalog:fixtures:synthetic'));
        self::assertSame(0, $fixtures->execute(['--products' => '400', '--categories' => '13', '--attributes' => '6', '--brands' => '8', '--skip-index' => true]));

        $bench = new CommandTester($application->find('catalog:benchmark'));
        self::assertSame(0, $bench->execute(['--repeat' => '2', '--reindex' => '30', '--per-page' => '5', '--output' => $this->output]), $bench->getDisplay());

        /** @var array<string, mixed> $report */
        $report = json_decode((string) file_get_contents($this->output), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(400, $report['rebuild']['products']);
        self::assertSame(['large', 'medium', 'small'], array_keys($report['categories']));
        self::assertCount(3 * 3 * 2, $report['listing'], 'категорії × фільтри 0/1/3 × фасети так/ні');
        self::assertArrayHasKey('catalog_filter_value', $report['table_sizes']);
        self::assertSame(30, $report['incremental']['reindex_products']['count']);
        foreach ($report['pagination'] as $page) {
            self::assertNotFalse($page['same_hits'], 'keyset і OFFSET віддають ту саму сторінку');
        }
        $filtered = array_filter($report['listing'], static fn (array $l): bool => 3 === $l['filters'] && 'large' === $l['category']);
        foreach ($filtered as $entry) {
            self::assertLessThanOrEqual($report['categories']['large']['products'], $entry['result_total']);
        }
    }
}
