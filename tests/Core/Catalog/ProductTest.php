<?php

declare(strict_types=1);

namespace App\Tests\Core\Catalog;

use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Media;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Enum\AttributeType;
use App\Core\Catalog\Enum\ProductKind;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    public function testNewProductHasOneDefaultVariant(): void
    {
        $product = new Product('ML-14', 'uah');

        self::assertCount(1, $product->getVariants());
        self::assertSame('ML-14', $product->getDefaultVariant()->getSku());
        self::assertSame('UAH', $product->getCurrency());
        self::assertSame(ProductKind::Goods, $product->getKind());
    }

    public function testLastVariantCannotBeRemoved(): void
    {
        $product = new Product('ML-14', 'UAH');

        $this->expectException(CatalogInvariantViolation::class);
        $product->removeVariant($product->getDefaultVariant());
    }

    public function testVariantsShareCurrencyAndHaveUniqueSku(): void
    {
        $product = new Product('ML-14-WHITE', 'UAH');
        $black = $product->addVariant('ML-14-BLACK');

        self::assertSame('UAH', $black->getCurrency());
        self::assertSame(1, $black->getPosition());
        self::assertSame($black, $product->findVariantBySku('ML-14-BLACK'));

        $product->removeVariant($product->getDefaultVariant());
        self::assertSame($black, $product->getDefaultVariant());

        $this->expectException(CatalogInvariantViolation::class);
        $product->addVariant('ML-14-BLACK');
    }

    public function testServiceVariantsAreNotStockTracked(): void
    {
        $service = new Product('MONTAZH', 'UAH', ProductKind::Service);
        self::assertSame(StockPolicy::NotTracked, $service->getDefaultVariant()->getStockPolicy());
        self::assertSame(StockPolicy::NotTracked, $service->addVariant('MONTAZH-2')->getStockPolicy());

        $goods = new Product('ZAMIR', 'UAH');
        $goods->setKind(ProductKind::Service);
        self::assertSame(StockPolicy::NotTracked, $goods->getDefaultVariant()->getStockPolicy());

        $this->expectException(CatalogInvariantViolation::class);
        $service->getDefaultVariant()->setStockPolicy(StockPolicy::InStock);
    }

    public function testMainCategoryIsAlwaysOneOfCategories(): void
    {
        $product = new Product('SKU', 'UAH');
        $doors = new Category();
        $sale = new Category();

        $product->addCategory($doors);
        $product->addCategory($sale);
        self::assertSame($doors, $product->getMainCategory(), 'Перша категорія стає головною');

        $product->setMainCategory($sale);
        $product->removeCategory($sale);
        self::assertSame($doors, $product->getMainCategory(), 'Видалення головної переносить її на іншу');

        $product->removeCategory($doors);
        self::assertNull($product->getMainCategory());

        $this->expectException(CatalogInvariantViolation::class);
        $product->setMainCategory($sale);
    }

    public function testSelectAttributeKeepsSingleValue(): void
    {
        $product = new Product('SKU', 'UAH');
        $material = new Attribute('material', AttributeType::Select);
        $mdf = $material->addValue('mdf');
        $pine = $material->addValue('pine');

        $product->addAttributeOption($mdf);
        $product->addAttributeOption($pine);

        $values = $product->getAttributeValues($material);
        self::assertCount(1, $values);
        self::assertSame($pine, $values[0]->getOption());
    }

    public function testMultiSelectAccumulatesWithoutDuplicates(): void
    {
        $product = new Product('SKU', 'UAH');
        $features = new Attribute('features', AttributeType::MultiSelect);
        $glass = $features->addValue('glass');
        $lock = $features->addValue('lock');

        $product->addAttributeOption($glass);
        $product->addAttributeOption($lock);
        $product->addAttributeOption($glass);
        self::assertCount(2, $product->getAttributeValues($features));

        $product->removeAttributeOption($glass);
        self::assertCount(1, $product->getAttributeValues($features));
    }

    public function testTypedAttributeValues(): void
    {
        $product = new Product('SKU', 'UAH');
        $weight = new Attribute('weight', AttributeType::Number);
        $nsf = new Attribute('nsf_listed', AttributeType::Boolean);

        $product->setAttributeNumber($weight, '96,5');
        $product->setAttributeNumber($weight, '97');
        $product->setAttributeBoolean($nsf, true);

        self::assertCount(2, $product->getAttributeValues());
        self::assertSame('97.0000', $product->getAttributeValues($weight)[0]->getNumberValue());
        self::assertTrue($product->getAttributeValues($nsf)[0]->getBooleanValue());

        $this->expectException(CatalogInvariantViolation::class);
        $product->setAttributeBoolean($weight, true);
    }

    public function testMediaIsLinkedOnceAndOnlyToOwnVariants(): void
    {
        $product = new Product('SKU', 'UAH');
        $photo = new Media('products/ml-14.webp', 'image/webp', 12_345);
        $black = $product->addVariant('SKU-BLACK');

        $link = $product->addMedia($photo, $black);
        self::assertSame($black, $link->getVariant());

        $product->removeVariant($black);
        self::assertNull($link->getVariant(), 'Фото видаленого варіанта стає фото картки');

        $other = new Product('OTHER', 'UAH');
        try {
            $link->setVariant($other->getDefaultVariant());
            self::fail('Очікувався виняток про чужий варіант');
        } catch (CatalogInvariantViolation) {
        }

        $this->expectException(CatalogInvariantViolation::class);
        $product->addMedia($photo);
    }

    public function testTranslations(): void
    {
        $product = new Product('SKU', 'UAH');
        $product->translate('uk')->setName('Двері ML 14')->setSlug('dveri-ml-14');

        self::assertSame('dveri-ml-14', $product->getTranslation('uk')?->getSlug());
    }
}
