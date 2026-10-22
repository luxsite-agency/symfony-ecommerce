<?php

declare(strict_types=1);

namespace App\Tests\Core\Catalog;

use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Enum\AttributeType;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Shared\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProductVariantTest extends TestCase
{
    public function testPriceAndOldPrice(): void
    {
        $variant = (new Product('SKU', 'UAH'))->getDefaultVariant();
        $variant->setPrice(Money::of(426_200, 'UAH'), Money::of(1_065_500, 'UAH'));

        self::assertTrue($variant->getPrice()->equals(Money::of(426_200, 'UAH')));
        self::assertSame(1_065_500, $variant->getOldPrice()?->amount);

        $variant->setPrice(Money::of(500_000, 'UAH'));
        self::assertNull($variant->getOldPrice());
    }

    public function testOldPriceMustExceedPrice(): void
    {
        $variant = (new Product('SKU', 'UAH'))->getDefaultVariant();

        $this->expectException(CatalogInvariantViolation::class);
        $variant->setPrice(Money::of(1000, 'UAH'), Money::of(1000, 'UAH'));
    }

    public function testPriceInForeignCurrencyIsRejected(): void
    {
        $variant = (new Product('SKU', 'UAH'))->getDefaultVariant();

        $this->expectException(CatalogInvariantViolation::class);
        $variant->setPrice(Money::of(78_495, 'USD'));
    }

    public function testNegativePriceIsRejected(): void
    {
        $variant = (new Product('SKU', 'UAH'))->getDefaultVariant();

        $this->expectException(CatalogInvariantViolation::class);
        $variant->setPrice(Money::of(-1, 'UAH'));
    }

    public function testStockIsDecimalAndNonNegative(): void
    {
        $variant = (new Product('SKU', 'UAH'))->getDefaultVariant();
        $variant->setStock('12,5');
        self::assertSame('12.500', $variant->getStock());

        $this->expectException(InvalidArgumentException::class);
        $variant->setStock('-1');
    }

    public function testQuantityRules(): void
    {
        $variant = (new Product('SKU', 'UAH'))->getDefaultVariant();
        $variant->setQuantityRules('45', '45');
        $variant->setPackQuantity('45');

        self::assertSame('45.000', $variant->getMinQuantity());
        self::assertSame('45.000', $variant->getPackQuantity());

        $this->expectException(InvalidArgumentException::class);
        $variant->setQuantityRules('1', '0');
    }

    public function testSkuIsTrimmedAndRequired(): void
    {
        $variant = (new Product(' AMG WT-2472-2SH-WHEELS ', 'USD'))->getDefaultVariant();
        self::assertSame('AMG WT-2472-2SH-WHEELS', $variant->getSku());

        $this->expectException(InvalidArgumentException::class);
        $variant->changeSku('   ');
    }

    public function testPriceTiersAreUniquePerGroupAndQuantity(): void
    {
        $variant = (new Product('BOX-600', 'UAH'))->getDefaultVariant();
        $dealer = new CustomerGroup('dealer', 'Дилер');

        $variant->addPriceTier('45', Money::of(159_600, 'UAH'));
        $variant->addPriceTier('135', Money::of(148_200, 'UAH'));
        $variant->addPriceTier('45', Money::of(150_000, 'UAH'), $dealer);
        self::assertCount(3, $variant->getPriceTiers());

        $this->expectException(CatalogInvariantViolation::class);
        $variant->addPriceTier('45.000', Money::of(1, 'UAH'));
    }

    public function testPriceTierCurrencyMustMatch(): void
    {
        $variant = (new Product('BOX-600', 'UAH'))->getDefaultVariant();

        $this->expectException(CatalogInvariantViolation::class);
        $variant->addPriceTier('10', Money::of(100, 'USD'));
    }

    public function testAxisValuesOnePerAttributeAndUniqueCombination(): void
    {
        $color = new Attribute('color', AttributeType::Select);
        $color->setVariantAxis(true);
        $white = $color->addValue('white');
        $black = $color->addValue('black');

        $product = new Product('ML-14-WHITE', 'UAH');
        $first = $product->getDefaultVariant();
        $second = $product->addVariant('ML-14-BLACK');

        $first->setAxisValue($black);
        $first->setAxisValue($white);
        self::assertCount(1, $first->getAxisValues(), 'Нове значення осі замінює старе');
        self::assertSame($white, $first->getAxisValue($color));

        $second->setAxisValue($black);

        $this->expectException(CatalogInvariantViolation::class);
        $second->setAxisValue($white);
    }

    public function testNonAxisAttributeIsRejected(): void
    {
        $material = new Attribute('material', AttributeType::Select);
        $variant = (new Product('SKU', 'UAH'))->getDefaultVariant();

        $this->expectException(CatalogInvariantViolation::class);
        $variant->setAxisValue($material->addValue('mdf'));
    }
}
