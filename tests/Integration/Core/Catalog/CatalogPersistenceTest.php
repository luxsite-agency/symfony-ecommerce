<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog;

use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\Brand;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Media;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductAttributeValue;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Entity\VariantAxisValue;
use App\Core\Catalog\Enum\AttributeType;
use App\Core\Catalog\Enum\OptionValueKind;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Integration\Entity\ExternalRef;
use App\Core\Shared\Money\Money;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Схема каталогу на реальному MySQL: граф зберігається й читається, обмеження БД спрацьовують. */
final class CatalogPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testFullProductGraphRoundTrip(): void
    {
        $door = $this->doorWithCasing();
        $this->em->flush();
        $id = $door->getId();
        $this->em->clear();

        $loaded = $this->em->find(Product::class, $id);
        self::assertNotNull($loaded);
        self::assertSame('Двері ML 14', $loaded->translate('uk')->getName());
        self::assertSame(['ru', 'uk'], $loaded->getTranslatedLocales());
        self::assertSame('solo', $loaded->getBrand()?->getSlug());
        $main = $loaded->getMainCategory();
        self::assertNotNull($main);
        self::assertSame(['dveri', 'mizhkimnatni'], array_map(
            static fn (Category $c): ?string => $c->translate('uk')->getSlug(),
            [...$main->getAncestors(), $main],
        ));

        $white = $loaded->getDefaultVariant();
        self::assertSame('ML-14-WHITE', $white->getSku());
        self::assertSame('feed-ml-14-white', $white->getExternalId());
        self::assertTrue($white->getPrice()->equals(Money::of(426_200, 'UAH')));
        self::assertSame(1_065_500, $white->getOldPrice()?->amount);
        self::assertSame('3.000', $white->getStock());
        self::assertSame(['white'], $white->getAxisValues()->map(static fn (VariantAxisValue $v): string => $v->getValue()->getCode())->getValues());
        self::assertCount(2, $white->getPriceTiers());

        $black = $loaded->findVariantBySku('ML-14-BLACK');
        self::assertNotNull($black);
        self::assertSame(StockPolicy::MadeToOrder, $black->getStockPolicy());
        self::assertSame(21, $black->getLeadTimeDays());

        $casing = $loaded->findOptionGroup('casing')?->findValue('both_sides');
        self::assertNotNull($casing);
        self::assertSame(OptionValueKind::Component, $casing->getKind());
        self::assertSame('LYSHTVA-ML', $casing->getComponentVariant()?->getSku());
        self::assertSame('5.000', $casing->getComponentQuantity());
        self::assertSame(51_500, $loaded->findOptionGroup('size')?->findValue('s2000x910')?->getAmount()?->amount);

        self::assertCount(2, $loaded->getAttributeValues());
        $media = $loaded->getMedia()->first();
        self::assertNotFalse($media);
        self::assertSame($black, $media->getVariant());
    }

    public function testSkuIsUniqueAcrossProducts(): void
    {
        $this->em->persist(new Product('DUP-1', 'UAH'));
        $this->em->persist(new Product('DUP-1', 'UAH'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testExternalIdIsUniqueAcrossProducts(): void
    {
        $a = new Product('EXT-A', 'UAH');
        $a->getDefaultVariant()->setExternalId('crm-1');
        $b = new Product('EXT-B', 'UAH');
        $b->getDefaultVariant()->setExternalId('crm-1');
        $this->em->persist($a);
        $this->em->persist($b);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testExternalRefUniqueWithinSystemAndEntityType(): void
    {
        // Той самий номер у різних послідовностях KeyCRM (offer і product) — допустимо.
        $this->em->persist(new ExternalRef('keycrm', 'product_variant', 1, '1017'));
        $this->em->persist(new ExternalRef('keycrm', 'product', 1, '1017'));
        $this->em->flush();

        $this->em->persist(new ExternalRef('keycrm', 'product_variant', 2, '1017'));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    /** ADR Rev 9, п. 1: option_id NULL не порівнюється в UNIQUE, тому ключем є generated column option_key. */
    public function testDuplicateNumberAttributeValueFailsOnUnique(): void
    {
        $height = new Attribute('height_mm', AttributeType::Number);
        $door = new Product('ML-14', 'UAH');
        $door->setAttributeNumber($height, '2000');
        $this->em->persist($height);
        $this->em->persist($door);
        $this->em->flush();

        // Обхід Product::setAttributeNumber(), який замінив би значення: другий рядок того ж атрибута напряму.
        $this->em->persist(ProductAttributeValue::number($door, $height, '2100'));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testResettingNumberAttributeUpdatesSameRow(): void
    {
        $height = new Attribute('height_mm', AttributeType::Number);
        $door = new Product('ML-14', 'UAH');
        $door->setAttributeNumber($height, '2000');
        $this->em->persist($height);
        $this->em->persist($door);
        $this->em->flush();
        $rowId = $door->getAttributeValues($height)[0]->getId();

        // remove+add в одному flush дав би INSERT до DELETE і дубль (product, attribute, option_key=0)
        $door->setAttributeNumber($height, '2100');
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->find(Product::class, $door->getId());
        self::assertNotNull($loaded);
        $height = $this->em->find(Attribute::class, $height->getId());
        self::assertNotNull($height);
        $values = $loaded->getAttributeValues($height);
        self::assertCount(1, $values);
        self::assertSame($rowId, $values[0]->getId());
        self::assertSame('2100.0000', $values[0]->getNumberValue());
    }

    public function testDuplicateBooleanAttributeValueFailsOnUnique(): void
    {
        $flag = new Attribute('has_glass', AttributeType::Boolean);
        $door = new Product('ML-14', 'UAH');
        $door->setAttributeBoolean($flag, true);
        $this->em->persist($flag);
        $this->em->persist($door);
        $this->em->flush();

        $this->em->persist(ProductAttributeValue::boolean($door, $flag, false));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testMultiselectKeepsSeveralOptionsOfOneAttribute(): void
    {
        $features = new Attribute('features', AttributeType::MultiSelect);
        $glass = $features->addValue('glass');
        $lock = $features->addValue('lock');
        $door = new Product('ML-14', 'UAH');
        $door->addAttributeOption($glass);
        $door->addAttributeOption($lock);
        $this->em->persist($features);
        $this->em->persist($door);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->find(Product::class, $door->getId());
        self::assertNotNull($loaded);
        self::assertSame([$glass->getId(), $lock->getId()], array_map(
            static fn (ProductAttributeValue $v): int => $v->getOptionKey(),
            $loaded->getAttributeValues(),
        ));
    }

    /** ADR Rev 9, п. 2: UNIQUE(variant_id, attribute_id) тримає «одне значення на вісь» у БД, а не лише в setAxisValue(). */
    public function testTwoAxisValuesOfOneAttributeFailOnUnique(): void
    {
        $color = new Attribute('color', AttributeType::Select);
        $color->setVariantAxis(true);
        $white = $color->addValue('white');
        $black = $color->addValue('black');
        $door = new Product('ML-14', 'UAH');
        $variant = $door->getDefaultVariant();
        $variant->setAxisValue($white);
        $this->em->persist($color);
        $this->em->persist($door);
        $this->em->flush();

        // Обхід setAxisValue(), який замінив би значення на місці: другий рядок тієї ж осі напряму.
        $this->em->persist(new VariantAxisValue($variant, $black));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testReplacingAxisValueUpdatesRowInPlace(): void
    {
        $color = new Attribute('color', AttributeType::Select);
        $color->setVariantAxis(true);
        $white = $color->addValue('white');
        $black = $color->addValue('black');
        $door = new Product('ML-14', 'UAH');
        $variant = $door->getDefaultVariant();
        $variant->setAxisValue($white);
        $this->em->persist($color);
        $this->em->persist($door);
        $this->em->flush();
        $before = $variant->getAxisValues()->first();
        self::assertNotFalse($before);
        $axisId = $before->getId();

        $variant->setAxisValue($black);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->find(ProductVariant::class, $variant->getId());
        self::assertNotNull($loaded);
        self::assertCount(1, $loaded->getAxisValues());
        $axis = $loaded->getAxisValues()->first();
        self::assertNotFalse($axis);
        self::assertSame($axisId, $axis->getId(), 'Той самий рядок, не DELETE+INSERT');
        self::assertSame('black', $axis->getValue()->getCode());
    }

    public function testVariantUsedAsComponentCannotBeDeleted(): void
    {
        $this->doorWithCasing();
        $this->em->flush();
        $casing = $this->em->getRepository(ProductVariant::class)->findOneBy(['sku' => 'LYSHTVA-ML']);
        self::assertNotNull($casing);

        $this->em->remove($casing->getProduct());
        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->em->flush();
    }

    private function doorWithCasing(): Product
    {
        $doors = new Category();
        $doors->translate('uk')->setName('Двері')->setSlug('dveri');
        $interior = new Category($doors);
        $interior->translate('uk')->setName('Міжкімнатні')->setSlug('mizhkimnatni');

        $brand = new Brand('Solo', 'solo');

        $color = new Attribute('color', AttributeType::Select);
        $color->setVariantAxis(true);
        $color->translate('uk')->setName('Колір');
        $white = $color->addValue('white');
        $white->translate('uk')->setValue('Білий');
        $black = $color->addValue('black');
        $material = new Attribute('material', AttributeType::Select);
        $mdf = $material->addValue('mdf');
        $height = new Attribute('height_mm', AttributeType::Number);
        $height->setUnit('мм');

        $casing = new Product('LYSHTVA-ML', 'UAH');
        $casing->getDefaultVariant()->setPrice(Money::of(50_000, 'UAH'));

        $door = new Product('ML-14-WHITE', 'UAH');
        $door->translate('uk')->setName('Двері ML 14')->setSlug('dveri-ml-14');
        $door->translate('ru')->setName('Двери ML 14')->setSlug('dveri-ml-14');
        $door->setBrand($brand);
        $door->addCategory($interior);
        $door->addAttributeOption($mdf);
        $door->setAttributeNumber($height, '2000');

        $whiteVariant = $door->getDefaultVariant();
        $whiteVariant->setPrice(Money::of(426_200, 'UAH'), Money::of(1_065_500, 'UAH'));
        $whiteVariant->setExternalId('feed-ml-14-white');
        $whiteVariant->setStock('3');
        $whiteVariant->setAxisValue($white);
        $whiteVariant->addPriceTier('5', Money::of(400_000, 'UAH'));
        $whiteVariant->addPriceTier('5', Money::of(380_000, 'UAH'), new CustomerGroup('dealer', 'Дилер'));

        $blackVariant = $door->addVariant('ML-14-BLACK');
        $blackVariant->setPrice(Money::of(456_200, 'UAH'));
        $blackVariant->setStockPolicy(StockPolicy::MadeToOrder);
        $blackVariant->setLeadTimeDays(21);
        $blackVariant->setAxisValue($black);

        $size = $door->addOptionGroup('size');
        $size->translate('uk')->setName('Полотно');
        $size->markDefault($size->addAbsoluteModifier('s2000x810', Money::zero('UAH')));
        $size->addAbsoluteModifier('s2000x910', Money::of(51_500, 'UAH'))->translate('uk')->setLabel('2000×910');
        $door->addOptionGroup('casing')->addComponent('both_sides', $casing->getDefaultVariant(), '5');

        $door->addMedia(new Media('products/ml-14-black.webp', 'image/webp', 48_000), $blackVariant);

        foreach ([$doors, $interior, $brand, $color, $material, $height, $casing, $door] as $entity) {
            $this->em->persist($entity);
        }
        foreach ($door->getVariants() as $variant) {
            foreach ($variant->getPriceTiers() as $tier) {
                if (null !== $tier->getCustomerGroup()) {
                    $this->em->persist($tier->getCustomerGroup());
                }
            }
        }
        foreach ($door->getMedia() as $link) {
            $this->em->persist($link->getMedia());
        }

        return $door;
    }
}
