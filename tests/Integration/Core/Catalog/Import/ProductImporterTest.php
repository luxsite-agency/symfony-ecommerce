<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog\Import;

use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\ImportRun;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\ImportRunStatus;
use App\Core\Catalog\Import\Exception\ImportAlreadyRunningException;
use App\Core\Catalog\Import\Exception\InvalidImportRowException;
use App\Core\Catalog\Import\Message\ProcessImportChunk;
use App\Core\Catalog\Import\MessageHandler\ProcessImportChunkHandler;
use App\Core\Catalog\Import\ProductImporter;
use App\Core\Catalog\Import\ProductImportRow;
use App\Core\Catalog\Import\VariantImportRow;
use App\Core\Integration\Entity\ExternalRef;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Імпорт на MySQL: ідемпотентність, зникнення з фіду → stock=0, lock, невалідні рядки, async-чанки. */
final class ProductImporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ProductImporter $importer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->importer = self::getContainer()->get(ProductImporter::class);
    }

    public function testSameFeedTwiceIsIdempotent(): void
    {
        $first = $this->importer->import('supplier', $this->feed(), chunkSize: 2, sync: true);
        self::assertSame(ImportRunStatus::Completed, $first->getStatus());
        self::assertSame([3, 0, 0, 0, 2], [$first->getProductsCreated(), $first->getProductsUpdated(), $first->getProductsUnchanged(), $first->getVariantsRepriced(), $first->getTotalChunks()]);
        self::assertSame([], $first->getErrors());

        $second = $this->importer->import('supplier', $this->feed(), chunkSize: 2, sync: true);
        self::assertSame([0, 0, 3, 0, 0], [$second->getProductsCreated(), $second->getProductsUpdated(), $second->getProductsUnchanged(), $second->getVariantsRepriced(), $second->getVariantsDisappeared()]);

        $this->em->clear();
        self::assertSame(3, $this->em->getRepository(Product::class)->count([]));
        self::assertSame(4, $this->em->getRepository(ProductVariant::class)->count([]));
        self::assertSame(3, $this->em->getRepository(ExternalRef::class)->count(['system' => 'feed_supplier', 'entityType' => 'product']));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM catalog_import_staging'), 'Staging очищено');

        $ml14 = $this->productBySku('ML-14-WHITE');
        self::assertSame('Двері ML 14', $ml14->translate('uk')->getName());
        self::assertSame('dveri-ml-14', $ml14->translate('uk')->getSlug());
        self::assertSame('solo', $ml14->getBrand()?->getSlug());
        self::assertSame('Двері / Міжкімнатні', $ml14->getMainCategory()?->getPathLabel());
        self::assertCount(2, $ml14->getVariants());
        $black = $ml14->findVariantBySku('ML-14-BLACK');
        self::assertNotNull($black);
        self::assertSame('1002', $black->getExternalId());
        $axis = $black->getAxisValues()->first();
        self::assertNotFalse($axis);
        self::assertSame('black', $axis->getValue()->getCode());
        $material = $this->em->getRepository(Attribute::class)->findOneBy(['code' => 'material']);
        self::assertNotNull($material);
        self::assertSame('mdf', $ml14->getAttributeValues($material)[0]->getOption()?->getCode());
        $height = $this->em->getRepository(Attribute::class)->findOneBy(['code' => 'height_mm']);
        self::assertNotNull($height);
        self::assertSame('2000.0000', $ml14->getAttributeValues($height)[0]->getNumberValue());
        self::assertNotNull($ml14->getSourceHash());
    }

    /**
     * Регресія PR #27: UkrainianSlugger (КМУ-2010) — лише для URL. Машинні коди характеристик/значень і слаги брендів,
     * за якими імпорт знаходить наявні записи, лишаються транслітерацією глобального slugger (chornyy, levi-s): база,
     * імпортована до PR, при повторному імпорті не отримує дублів.
     */
    public function testUrlSluggerDoesNotChangeImportLookupKeys(): void
    {
        // стан бази «до PR #27»: записи з кодами старого слагера
        $legacy = new \Symfony\Component\String\Slugger\AsciiSlugger();
        $code = static fn (string $text): string => str_replace('-', '_', $legacy->slug($text, '-', 'uk')->lower()->toString());
        self::assertSame('chornyy', $code('Чорний'), 'передумова: старий слагер дає -yy, новий — -yi');
        $colour = new Attribute($code('Колір виробу'), \App\Core\Catalog\Enum\AttributeType::Select);
        $colour->setVariantAxis(true);
        $colour->addValue($code('Чорний'));
        $this->em->persist($colour);
        $this->em->persist(new \App\Core\Catalog\Entity\Brand("Levi's", $legacy->slug("Levi's", '-', 'uk')->lower()->toString()));
        $this->em->flush();
        $countValues = fn (): int => (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM catalog_attribute_value');
        $countBrands = fn (): int => (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM catalog_brand');
        [$valuesBefore, $brandsBefore] = [$countValues(), $countBrands()];

        $feed = [$this->row('L1', 'Футболка військова', [
            $this->variant('L1-1', 'TEE-BLK', 50_000, '1', null, ['Колір виробу' => 'Чорний']),
            $this->variant('L1-2', 'TEE-OLV', 50_000, '1', null, ['Колір виробу' => "Оливковий з м'ятним"]),
        ], categories: [['Одяг військовий']], brand: "Levi's", attributes: ['Матеріал' => 'Бавовняний']),
        ];
        foreach ([1, 2] as $run) {
            $result = $this->importer->import('supplier', $feed, sync: true);
            self::assertSame([], $result->getErrors(), 'прогін '.$run);
        }

        $this->em->clear();
        self::assertSame($brandsBefore, $countBrands(), "Levi's знайдено за старим слагом levi-s, нового бренду нема");
        self::assertSame($valuesBefore + 2, $countValues(), 'нові лише «Оливковий з мʼятним» і «Бавовняний», «Чорний» — наявний chornyy, повтор — без дублів');
        $tee = $this->productBySku('TEE-BLK');
        self::assertSame('levi-s', $tee->getBrand()?->getSlug());
        $black = $tee->findVariantBySku('TEE-BLK')?->getAxisValues()->first();
        self::assertNotFalse($black);
        self::assertSame('chornyy', $black->getValue()->getCode());
        self::assertSame($colour->getCode(), $black->getAttribute()->getCode());
        $olive = $tee->findVariantBySku('TEE-OLV')?->getAxisValues()->first();
        self::assertNotFalse($olive);
        self::assertSame($code("Оливковий з м'ятним"), $olive->getValue()->getCode(), 'новий код — тим самим старим слагером');

        // URL-слаги — новий слагер (КМУ-2010): «військова» → viiskova, без дефіса на мʼякому знаку
        self::assertSame('futbolka-viiskova', $tee->translate('uk')->getSlug());
        self::assertSame('odiah-viiskovyi', $tee->getMainCategory()?->getTranslation('uk')?->getSlug());
    }

    /**
     * Регресія en-pharmacy 19.10.2026: повторний імпорт зміненої картки з мультиатрибутом падав на
     * «Duplicate entry … uniq_catalog_product_attribute_value» — значення видалялись і додавались заново, а Doctrine
     * виконує INSERT раніше за DELETE. Тепер набір значень звіряється: наявні лишаються, зайві йдуть, нові додаються;
     * повтор у списку (a|a) і той самий набір в іншому порядку — без дублів.
     */
    public function testMultiSelectAttributeSurvivesReimportOfChangedProduct(): void
    {
        $feed = fn (string $description, array $potency): array => [new ProductImportRow('H1', ['uk' => 'Abies Nigra'], [$this->variant('H1-1', 'KP-H1', 1_200)], descriptions: ['uk' => $description], attributes: ['option_potency' => $potency, 'disclaimer' => 'yes'])];

        $first = $this->importer->import('supplier', $feed('<p>v1</p>', ['6c', '12c', '30c', '30c']), sync: true);
        self::assertSame([], $first->getErrors());
        $second = $this->importer->import('supplier', $feed('<p>v2</p>', ['30c', '12c', '6c', '200c']), sync: true);
        self::assertSame([], $second->getErrors());
        self::assertSame(1, $second->getProductsUpdated());

        $this->em->clear();
        $potency = $this->em->getRepository(Attribute::class)->findOneBy(['code' => 'option_potency']);
        self::assertNotNull($potency);
        $codes = array_map(static fn ($v) => $v->getOption()?->getCode(), $this->productBySku('KP-H1')->getAttributeValues($potency));
        sort($codes);
        self::assertSame(['v_12c', 'v_200c', 'v_30c', 'v_6c'], $codes);
        self::assertSame('<p>v2</p>', $this->productBySku('KP-H1')->translate('uk')->getDescription());

        $third = $this->importer->import('supplier', $feed('<p>v3</p>', ['6c']), sync: true);
        self::assertSame([], $third->getErrors());
        $this->em->clear();
        $potency = $this->em->getRepository(Attribute::class)->findOneBy(['code' => 'option_potency']);
        self::assertNotNull($potency);
        self::assertSame(['v_6c'], array_map(static fn ($v) => $v->getOption()?->getCode(), $this->productBySku('KP-H1')->getAttributeValues($potency)), 'зайві значення пішли, спільне лишилось');
    }

    public function testPriceAndStockChangeGoesThroughBatchWithoutTouchingContent(): void
    {
        $this->importer->import('supplier', $this->feed(), sync: true);
        $hashBefore = $this->productBySku('FORT-1')->getSourceHash();

        $run = $this->importer->import('supplier', $this->feed(fortPrice: 1_300_000, fortStock: '2'), sync: true);
        self::assertSame(1, $run->getVariantsRepriced());
        self::assertSame(3, $run->getProductsUnchanged(), 'Контент не змінився — ORM не чіпав');

        $this->em->clear();
        $fort = $this->productBySku('FORT-1');
        self::assertSame(1_300_000, $fort->getDefaultVariant()->getPrice()->amount);
        self::assertSame('2.000', $fort->getDefaultVariant()->getStock());
        self::assertSame($hashBefore, $fort->getSourceHash());
    }

    public function testVariantsMissingFromFeedGetZeroStockOnlyWithinSource(): void
    {
        $this->importer->import('supplier', $this->feed(), sync: true);
        $this->importer->import('other', [$this->row('OTH', 'Інше', [$this->variant('o1', 'OTHER-1', 100, '9')])], sync: true);

        $run = $this->importer->import('supplier', [$this->row('ML14', 'Двері ML 14', [$this->variant('1001', 'ML-14-WHITE', 426_200, '3', axes: ['color' => 'white'])], categories: [['Двері', 'Міжкімнатні']], brand: 'Solo', attributes: ['material' => 'МДФ', 'height_mm' => '2000'])], sync: true);
        self::assertSame(2, $run->getVariantsDisappeared(), 'ML-14-BLACK і FORT-1 зникли з фіду');

        $this->em->clear();
        self::assertSame('0.000', $this->variantBySku('ML-14-BLACK')->getStock());
        self::assertSame('0.000', $this->variantBySku('FORT-1')->getStock());
        self::assertSame('3.000', $this->variantBySku('ML-14-WHITE')->getStock());
        self::assertSame('9.000', $this->variantBySku('OTHER-1')->getStock(), 'Інше джерело не зачеплено');

        $again = $this->importer->import('supplier', $this->feed(), sync: true);
        self::assertSame(0, $again->getVariantsDisappeared());
        self::assertSame('5.000', $this->variantBySku('FORT-1')->getStock(), 'Повернулось у фід — залишок відновлено з фіду');
    }

    public function testLockPreventsParallelRun(): void
    {
        $lock = self::getContainer()->get(LockFactory::class)->createLock('catalog-import:supplier', 60);
        self::assertTrue($lock->acquire());
        try {
            $this->expectException(ImportAlreadyRunningException::class);
            $this->importer->import('supplier', $this->feed(), sync: true);
        } finally {
            $lock->release();
        }
        self::assertSame(0, $this->em->getRepository(ImportRun::class)->count([]), 'Run не створено');
    }

    public function testAbandonedSyncRunIsClosedByNextImport(): void
    {
        // процес sync-запуску помер (OOM/kill) — run лишився running, lock відпустила ОС
        $dead = new ImportRun('supplier', true);
        $this->em->persist($dead);
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO catalog_import_staging (run_id, chunk_no, product_external_id, variant_external_id, sku, price_minor, currency, stock, content_hash, payload) VALUES (?, 0, ?, ?, ?, 1, ?, 1, ?, ?)',
            [$dead->getId(), 'x', 'x1', 'X-1', 'UAH', str_repeat('0', 40), '{}'],
        );

        $run = $this->importer->import('supplier', $this->feed(), sync: true);
        self::assertSame(ImportRunStatus::Completed, $run->getStatus());

        $this->em->clear();
        $closed = $this->em->find(ImportRun::class, $dead->getId());
        self::assertNotNull($closed);
        self::assertSame(ImportRunStatus::Failed, $closed->getStatus());
        self::assertNotNull($closed->getFinishedAt());
        self::assertStringContainsString('аварійно', $closed->getErrors()[0]);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM catalog_import_staging WHERE run_id = ?', [$dead->getId()]), 'staging покинутого запуску прибрано');
    }

    public function testInvalidRowsAreReportedAndValidOnesImported(): void
    {
        $rows = [$this->row('OK', 'Добрий', [$this->variant('ok1', 'OK-1', 100)]), new InvalidImportRowException('offer 77: Від\'ємна ціна')];
        $run = $this->importer->import('supplier', $rows, sync: true);
        self::assertSame(1, $run->getProductsCreated());
        self::assertSame(1, $run->getInvalidRows());
        self::assertSame(['offer 77: Від\'ємна ціна'], $run->getErrors());
    }

    public function testAsyncChunksAreDispatchedAndLastHandlerFinalizes(): void
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $handler = new ProcessImportChunkHandler($this->importer);

        $run = $this->importer->import('supplier', $this->feed(), chunkSize: 1, sync: false);
        self::assertSame(ImportRunStatus::Running, $run->getStatus());
        self::assertSame(3, $run->getTotalChunks());
        $messages = array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent());
        self::assertCount(3, $messages);
        self::assertContainsOnlyInstancesOf(ProcessImportChunk::class, $messages);

        $this->expectExceptionObject(new ImportAlreadyRunningException(\sprintf('Імпорт джерела "supplier" ще обробляє чанки запуску #%d', (int) $run->getId())));
        try {
            $this->importer->import('supplier', $this->feed(), sync: true);
        } finally {
            foreach ($messages as $message) {
                $handler($message);
            }
            $this->em->clear();
            $finished = $this->em->find(ImportRun::class, $run->getId());
            self::assertNotNull($finished);
            self::assertSame(ImportRunStatus::Completed, $finished->getStatus());
            self::assertSame(3, $finished->getProcessedChunks());
            self::assertSame(3, $finished->getProductsCreated());
            self::assertSame(4, $this->em->getRepository(ProductVariant::class)->count([]));
        }
    }

    /** @return list<ProductImportRow> */
    private function feed(int $fortPrice = 1_200_000, string $fortStock = '5'): array
    {
        return [
            $this->row('ML14', 'Двері ML 14', [
                $this->variant('1001', 'ML-14-WHITE', 426_200, '3', 500_000, ['color' => 'white']),
                $this->variant('1002', 'ML-14-BLACK', 456_250, '0', null, ['color' => 'black']),
            ], categories: [['Двері', 'Міжкімнатні']], brand: 'Solo', attributes: ['material' => 'МДФ', 'height_mm' => '2000']),
            $this->row('2001', 'Двері вхідні Форт', [$this->variant('2001', 'FORT-1', $fortPrice, $fortStock)], categories: [['Двері', 'Вхідні']], attributes: ['material' => 'Метал']),
            $this->row('3001', 'Ручка', [$this->variant('3001', 'HANDLE-1', 35_000, '100')], categories: [['Фурнітура']]),
        ];
    }

    /**
     * @param list<VariantImportRow>     $variants
     * @param list<list<string>>         $categories
     * @param array<string, string|bool> $attributes
     */
    private function row(string $externalId, string $name, array $variants, array $categories = [], ?string $brand = null, array $attributes = []): ProductImportRow
    {
        return new ProductImportRow($externalId, ['uk' => $name], $variants, brand: $brand, categoryPaths: $categories, attributes: $attributes);
    }

    /** @param array<string, string> $axes */
    private function variant(string $externalId, string $sku, int $price, string $stock = '1', ?int $old = null, array $axes = []): VariantImportRow
    {
        return new VariantImportRow($externalId, $sku, $price, 'UAH', $stock, $old, axes: $axes);
    }

    private function productBySku(string $sku): Product
    {
        return $this->variantBySku($sku)->getProduct();
    }

    private function variantBySku(string $sku): ProductVariant
    {
        $variant = $this->em->getRepository(ProductVariant::class)->findOneBy(['sku' => $sku]);
        self::assertNotNull($variant, $sku);

        return $variant;
    }
}
