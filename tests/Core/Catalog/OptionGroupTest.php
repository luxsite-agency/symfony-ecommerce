<?php

declare(strict_types=1);

namespace App\Tests\Core\Catalog;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Enum\ModifierType;
use App\Core\Catalog\Enum\OptionSelection;
use App\Core\Catalog\Enum\OptionValueKind;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Shared\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Набір дверей за типовим дверним магазином (ADR Rev 6): полотно + доплати + компоненти-SKU. */
final class OptionGroupTest extends TestCase
{
    public function testModifiersAndComponents(): void
    {
        $door = new Product('ML-14', 'UAH');
        $casing = new Product('LYSHTVA-ML', 'UAH');

        $size = $door->addOptionGroup('size', OptionSelection::Single, true);
        $standard = $size->addAbsoluteModifier('s2000x810', Money::zero('UAH'));
        $wide = $size->addAbsoluteModifier('s2000x910', Money::of(51_500, 'UAH'));
        $custom = $size->addPercentModifier('custom', 1500);

        $trim = $door->addOptionGroup('casing');
        $bothSides = $trim->addComponent('both_sides', $casing->getDefaultVariant(), '5');
        $oneSide = $trim->addComponent('one_side', $casing->getDefaultVariant(), '2,5', Money::of(125_000, 'UAH'));

        self::assertSame(OptionValueKind::Modifier, $wide->getKind());
        self::assertSame(ModifierType::Absolute, $wide->getModifierType());
        self::assertSame(51_500, $wide->getAmount()?->amount);
        self::assertNull($wide->getComponentVariant());

        self::assertSame(ModifierType::Percent, $custom->getModifierType());
        self::assertSame(1500, $custom->getBasisPoints());
        self::assertNull($custom->getAmount());

        self::assertSame(OptionValueKind::Component, $bothSides->getKind());
        self::assertNull($bothSides->getModifierType());
        self::assertSame('5.000', $bothSides->getComponentQuantity());
        self::assertNull($bothSides->getSelectionPrice());
        self::assertSame('2.500', $oneSide->getComponentQuantity());
        self::assertSame(125_000, $oneSide->getSelectionPrice()?->amount);

        self::assertSame([$standard, $wide, $custom], array_values($size->getValues()->toArray()));
        self::assertSame($trim, $door->findOptionGroup('casing'));
    }

    public function testGroupAndValueCodesAreUnique(): void
    {
        $door = new Product('ML-14', 'UAH');
        $group = $door->addOptionGroup('hinges');
        $group->addAbsoluteModifier('hidden', Money::of(180_000, 'UAH'));

        try {
            $group->addPercentModifier('hidden', 100);
            self::fail('Очікувався виняток про дубль значення');
        } catch (CatalogInvariantViolation) {
        }

        $this->expectException(CatalogInvariantViolation::class);
        $door->addOptionGroup('hinges');
    }

    public function testModifierMustBeInProductCurrency(): void
    {
        $group = (new Product('ML-14', 'UAH'))->addOptionGroup('lock');

        $this->expectException(CatalogInvariantViolation::class);
        $group->addAbsoluteModifier('lock', Money::of(1010, 'USD'));
    }

    public function testPercentDiscountCannotReachHundred(): void
    {
        $group = (new Product('ML-14', 'UAH'))->addOptionGroup('promo');

        $this->expectException(CatalogInvariantViolation::class);
        $group->addPercentModifier('free', -10_000);
    }

    public function testComponentCannotBeOwnVariant(): void
    {
        $door = new Product('ML-14', 'UAH');
        $group = $door->addOptionGroup('frame');

        $this->expectException(CatalogInvariantViolation::class);
        $group->addComponent('self', $door->addVariant('ML-14-FRAME'), '1');
    }

    public function testComponentQuantityMustBePositive(): void
    {
        $group = (new Product('ML-14', 'UAH'))->addOptionGroup('frame');
        $frame = new Product('KOROBKA', 'UAH');

        $this->expectException(InvalidArgumentException::class);
        $group->addComponent('frame', $frame->getDefaultVariant(), '0');
    }

    public function testComponentMustBeInProductCurrency(): void
    {
        $group = (new Product('ML-14', 'UAH'))->addOptionGroup('frame');
        $frame = new Product('KOROBKA-EU', 'EUR');
        $frame->getDefaultVariant()->setPrice(Money::of(4_000, 'EUR'));

        try {
            $group->addComponent('frame', $frame->getDefaultVariant(), '1');
            self::fail('Очікувався виняток про валюту компонента');
        } catch (CatalogInvariantViolation $e) {
            self::assertStringContainsString('EUR', $e->getMessage());
        }
        self::assertCount(0, $group->getValues(), 'Компонент у чужій валюті не додано');
    }

    public function testComponentSelectionPriceInProductCurrency(): void
    {
        $group = (new Product('ML-14', 'UAH'))->addOptionGroup('frame');
        $frame = new Product('KOROBKA', 'UAH');

        $this->expectException(CatalogInvariantViolation::class);
        $group->addComponent('frame', $frame->getDefaultVariant(), '1', Money::of(100, 'EUR'));
    }

    public function testOnRequestComponentNeedsSelectionPrice(): void
    {
        $group = (new Product('ML-14', 'UAH'))->addOptionGroup('mount');
        $mount = new Product('MONTAZH', 'UAH');
        $mount->setPriceMode(PriceMode::OnRequest);

        try {
            $group->addComponent('mount', $mount->getDefaultVariant(), '1');
            self::fail('Компонент «за запитом» без ціни вибору продався б за 0');
        } catch (CatalogInvariantViolation $e) {
            self::assertStringContainsString('ціна за запитом', $e->getMessage());
        }
        self::assertCount(0, $group->getValues());
        $group->addComponent('mount', $mount->getDefaultVariant(), '1', Money::of(150_000, 'UAH'));
        self::assertCount(1, $group->getValues(), 'з явною ціною за весь вибір — можна');
    }

    public function testSingleSelectionHasAtMostOneDefault(): void
    {
        $group = (new Product('ML-14', 'UAH'))->addOptionGroup('opening');
        $right = $group->addAbsoluteModifier('right', Money::zero('UAH'));
        $left = $group->addAbsoluteModifier('left', Money::zero('UAH'));

        $group->markDefault($right);
        $group->markDefault($left);
        self::assertSame([$left], $group->getDefaultValues());

        $group->setSelection(OptionSelection::Multiple);
        $group->markDefault($right);
        self::assertCount(2, $group->getDefaultValues());

        $this->expectException(CatalogInvariantViolation::class);
        $group->setSelection(OptionSelection::Single);
    }
}
