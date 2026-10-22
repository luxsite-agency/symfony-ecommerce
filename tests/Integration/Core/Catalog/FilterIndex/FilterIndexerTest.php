<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog\FilterIndex;

use App\Core\Catalog\FilterIndex\FilterIndexer;
use App\Core\Catalog\FilterIndex\FilterIndexState;
use App\Core\Catalog\FilterIndex\Message\ReindexProducts;
use App\Core\Catalog\FilterIndex\ReindexProductsHandler;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Запис read-index (ADR Rev 9): рядок на варіант, прапорці наявності, предки категорій, синхронізація з ORM-змінами. */
final class FilterIndexerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;
    private FilterIndexer $indexer;
    private CatalogIndexFixture $fx;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = self::getContainer()->get(Connection::class);
        $this->indexer = self::getContainer()->get(FilterIndexer::class);
        $this->fx = new CatalogIndexFixture($this->em, bin2hex(random_bytes(3)));
        $this->transport()->reset(); // повідомлення від listener-а при створенні фікстури
        $this->indexer->reindexProducts($this->fx->productIds());
    }

    public function testVariantRowsCarryAvailabilityFlagsAndPriceMode(): void
    {
        $rows = $this->variantRows();
        $v = $this->fx->v;
        // listed / purchasable / in_stock
        self::assertSame([1, 1, 1], $rows[$v['V1']->getId()]['flags']);
        self::assertSame([1, 0, 0], $rows[$v['V4']->getId()]['flags'], 'in_stock з нульовим залишком — не купити');
        self::assertSame([1, 1, 0], $rows[$v['V5']->getId()]['flags'], 'під замовлення — купити можна, «в наявності» ні');
        self::assertSame([0, 0, 0], $rows[$v['V6']->getId()]['flags'], 'вимкнений товар — ніде, навіть «в наявності»');
        $p5 = $this->fx->p['P5']->getDefaultVariant()->getId();
        self::assertSame(2, $rows[$p5]['price_mode'], 'on_request');
        self::assertSame($this->fx->nike->getId(), $rows[$v['V1']->getId()]['brand_id']);
        self::assertSame(1000, $rows[$v['V1']->getId()]['price_minor']);
    }

    public function testCardValuesAreCopiedToEveryVariantAxesStayPerVariant(): void
    {
        $values = $this->db->fetchAllAssociative(
            'SELECT variant_id, attribute_id, option_id, number_value, boolean_value FROM catalog_filter_value WHERE product_id = ? ORDER BY variant_id, attribute_id, option_id',
            [$this->fx->id('P1')],
        );
        $byVariant = [];
        foreach ($values as $r) {
            $byVariant[(int) $r['variant_id']][] = [(int) $r['attribute_id'], (int) $r['option_id'], $r['number_value'], null === $r['boolean_value'] ? null : (int) $r['boolean_value']];
        }
        $expect = static fn (int $size, int $color, CatalogIndexFixture $fx): array => self::sorted([
            [(int) $fx->size->getId(), $size, null, null],
            [(int) $fx->color->getId(), $color, null, null],
            [(int) $fx->material->getId(), $fx->optionId('leather'), null, null],
            [(int) $fx->material->getId(), $fx->optionId('mesh'), null, null],
            [(int) $fx->weight->getId(), 0, '0.8000', null],
            [(int) $fx->waterproof->getId(), 0, null, 1],
        ]);
        self::assertSame($expect($this->fx->optionId('42'), $this->fx->optionId('red'), $this->fx), self::sorted($byVariant[$this->fx->v['V1']->getId()]));
        self::assertSame($expect($this->fx->optionId('38'), $this->fx->optionId('red'), $this->fx), self::sorted($byVariant[$this->fx->v['V2']->getId()]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_filter_value WHERE attribute_id = ?', [$this->fx->hidden->getId()]), 'не filterable — не індексується');
    }

    public function testProductCategoryRowsIncludeAllAncestors(): void
    {
        $categories = array_map(intval(...), $this->db->fetchFirstColumn('SELECT category_id FROM catalog_filter_product_category WHERE product_id = ? ORDER BY category_id', [$this->fx->id('P1')]));
        $expected = [(int) $this->fx->shoes->getId(), (int) $this->fx->sneakers->getId(), (int) $this->fx->running->getId()];
        sort($expected);
        self::assertSame($expected, $categories);
    }

    public function testReindexReplacesRowsOfProduct(): void
    {
        $this->db->executeStatement('DELETE FROM catalog_product_attribute_value WHERE product_id = ? AND attribute_id = ?', [$this->fx->id('P2'), $this->fx->material->getId()]);
        $this->indexer->reindexProducts([$this->fx->id('P2')]);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_filter_value WHERE product_id = ? AND attribute_id = ?', [$this->fx->id('P2'), $this->fx->material->getId()]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_filter_variant WHERE product_id = ?', [$this->fx->id('P2')]));
    }

    public function testStockChangeThroughOrmUpdatesVariantRowImmediately(): void
    {
        $v3 = $this->fx->v['V3'];
        $v3->setStock('0');
        $this->em->flush();
        self::assertSame([1, 0, 0], $this->variantRows()[$v3->getId()]['flags']);

        $v3->setPrice(Money::of(1400, 'UAH'));
        $this->em->flush();
        self::assertSame(1400, $this->variantRows()[$v3->getId()]['price_minor']);
        self::assertSame([], $this->transport()->getSent(), 'точкові зміни не йдуть через чергу');

        $this->fx->p['P2']->setActive(false);
        $this->em->flush();
        self::assertSame([0, 0, 0], $this->variantRows()[$v3->getId()]['flags'], 'active товару → UPDATE рядків усіх його варіантів');
    }

    public function testAttributeChangeThroughOrmIsReindexedViaMessenger(): void
    {
        $p2 = $this->fx->p['P2'];
        $p2->addAttributeOption($this->fx->opt['leather']);
        $this->em->flush();

        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(ReindexProducts::class, $message);
        self::assertSame([$this->fx->id('P2')], $message->productIds);
        self::assertSame(0, $this->countValue('P2', 'leather'), 'до обробки повідомлення індекс ще старий');

        self::getContainer()->get(ReindexProductsHandler::class)($message);
        self::assertSame(1, $this->countValue('P2', 'leather'));
    }

    public function testMovingCategoryReindexesProductsOfSubtree(): void
    {
        $this->fx->sneakers->setParent($this->fx->bags);
        $this->em->flush();

        $ids = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(ReindexProducts::class, $message);
            $ids = [...$ids, ...$message->productIds];
            self::getContainer()->get(ReindexProductsHandler::class)($message);
        }
        sort($ids);
        $expected = [$this->fx->id('P1'), $this->fx->id('P2'), $this->fx->id('P3'), $this->fx->id('P4')];
        sort($expected);
        self::assertSame($expected, $ids);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_filter_product_category WHERE product_id = ? AND category_id = ?', [$this->fx->id('P1'), $this->fx->bags->getId()]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_filter_product_category WHERE product_id = ? AND category_id = ?', [$this->fx->id('P1'), $this->fx->shoes->getId()]));
    }

    public function testDeletingProductOrCategoryCleansIndex(): void
    {
        $p2 = $this->fx->id('P2');
        $this->em->remove($this->fx->p['P2']);
        $this->em->flush();
        foreach (['catalog_filter_variant', 'catalog_filter_value', 'catalog_filter_product_category'] as $table) {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE product_id = ?', [$p2]), $table);
        }

        // Видалення категорії: товари її піддерева регенеруються (рядки «товар × предок» зникають)
        $this->transport()->reset();
        $this->em->remove($this->fx->running);
        $this->em->flush();
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(ReindexProducts::class, $message);
        self::assertSame([$this->fx->id('P1')], $message->productIds);
        self::getContainer()->get(ReindexProductsHandler::class)($message);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_filter_product_category WHERE product_id = ?', [$this->fx->id('P1')]));
    }

    public function testWritesDuringRebuildAreMirroredToDirtyList(): void
    {
        $state = self::getContainer()->get(FilterIndexState::class);
        $state->clearAllDirty();
        $state->setRebuilding(true);
        try {
            $this->indexer->reindexProducts([$this->fx->id('P1')]);
            $this->indexer->refreshVariants([(int) $this->fx->v['V3']->getId()]);
            $dirty = array_column($state->takeDirty(100), 'product_id');
            sort($dirty);
            $expected = [$this->fx->id('P1'), $this->fx->id('P2')];
            sort($expected);
            self::assertSame($expected, $dirty);
        } finally {
            $state->setRebuilding(false);
            $state->clearAllDirty();
        }
    }

    public function testRefreshVariantsUpdatedSinceCoversBatchSqlUpdates(): void
    {
        $since = new DateTimeImmutable('-1 second');
        $this->db->executeStatement('UPDATE catalog_product_variant SET stock = 0, updated_at = NOW() WHERE id IN (?)', [[(int) $this->fx->v['V1']->getId()]], [ArrayParameterType::INTEGER]);
        self::assertGreaterThanOrEqual(1, $this->indexer->refreshVariantsUpdatedSince($since));
        self::assertSame([1, 0, 0], $this->variantRows()[$this->fx->v['V1']->getId()]['flags']);
    }

    private function countValue(string $product, string $option): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_filter_value WHERE product_id = ? AND option_id = ?', [$this->fx->id($product), $this->fx->optionId($option)]);
    }

    /** @return array<int, array{flags: list<int>, price_mode: int, brand_id: ?int, price_minor: int}> */
    private function variantRows(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM catalog_filter_variant WHERE product_id IN (?)', [$this->fx->productIds()], [ArrayParameterType::INTEGER]);
        $result = [];
        foreach ($rows as $r) {
            $result[(int) $r['variant_id']] = [
                'flags' => [(int) $r['listed'], (int) $r['purchasable'], (int) $r['in_stock']],
                'price_mode' => (int) $r['price_mode'],
                'brand_id' => null === $r['brand_id'] ? null : (int) $r['brand_id'],
                'price_minor' => (int) $r['price_minor'],
            ];
        }

        return $result;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @param list<array{int, int, ?string, ?int}> $rows
     *
     * @return list<array{int, int, ?string, ?int}>
     */
    private static function sorted(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $rows;
    }
}
