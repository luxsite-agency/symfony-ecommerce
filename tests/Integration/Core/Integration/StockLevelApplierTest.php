<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Integration;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Integration\Crm\StockLevel;
use App\Core\Integration\Crm\StockLevelApplier;
use App\Core\Integration\Entity\ExternalRef;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Застосування рівнів залишку з CRM: за ExternalRef чи sku, ідемпотентно, невідомий sku — у звіт, від'ємне → 0. */
final class StockLevelApplierTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private StockLevelApplier $applier;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->applier = self::getContainer()->get(StockLevelApplier::class);
    }

    public function testAppliesBySkuLearnsRefAndIsIdempotent(): void
    {
        $product = new Product('A-1', 'UAH');
        $product->addVariant('A-2');
        $this->em->persist($product);
        $this->em->flush();

        $report = $this->applier->apply('keycrm', [new StockLevel('A-1', '10', '3', '1017'), new StockLevel('A-2', '1', '5', '1018'), new StockLevel('NOPE', '1')]);
        self::assertSame(1, $report->changed, 'A-1: 0 → 7');
        self::assertSame(1, $report->unchanged, 'A-2: 0 → 0 (обрізано), без змін');
        self::assertSame(1, $report->clamped);
        self::assertSame(['NOPE'], $report->unknownSkus);
        self::assertSame(2, $report->refsLearned);

        $this->em->clear();
        $a1 = $this->em->getRepository(ProductVariant::class)->findOneBy(['sku' => 'A-1']);
        $a2 = $this->em->getRepository(ProductVariant::class)->findOneBy(['sku' => 'A-2']);
        self::assertSame('7.000', $a1?->getStock());
        self::assertSame('0.000', $a2?->getStock());
        $ref = $this->em->getRepository(ExternalRef::class)->findOneBy(['system' => 'keycrm', 'entityType' => 'product_variant', 'externalId' => '1017']);
        self::assertSame($a1->getId(), $ref?->getEntityId());

        // Той самий вебхук ще раз: нічого не змінюється, ref не дублюється
        $again = $this->applier->apply('keycrm', [new StockLevel('A-1', '10', '3', '1017')]);
        self::assertSame(0, $again->changed);
        self::assertSame(1, $again->unchanged);
        self::assertSame(0, $again->refsLearned);
        self::assertCount(1, $this->em->getRepository(ExternalRef::class)->findBy(['system' => 'keycrm', 'externalId' => '1017']));
    }

    public function testExternalRefWinsOverSkuWhenSkuRenamed(): void
    {
        $product = new Product('OLD-SKU', 'UAH');
        $this->em->persist($product);
        $this->em->flush();
        $variant = $product->getDefaultVariant();
        $this->em->persist(new ExternalRef('keycrm', ExternalRef::TYPE_PRODUCT_VARIANT, (int) $variant->getId(), '77'));
        $this->em->flush();

        $report = $this->applier->apply('keycrm', [new StockLevel('RENAMED-IN-CRM', '4', '0', '77')]);
        self::assertSame(1, $report->changed);
        self::assertSame([], $report->unknownSkus);
        self::assertSame('4.000', $variant->getStock());
    }
}
