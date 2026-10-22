<?php

declare(strict_types=1);

namespace App\Tests\Core\Pricing;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\PriceResolver;
use App\Core\Shared\Money\Money;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Вибір порога (рішення власника 07.10.2026): у групи є хоч один поріг для варіанта — загальні для неї не діють;
 * групи порівнюються за id, не за екземпляром (консиліум 07.10.2026, розділ 3 п. 1 і таблиця п. 3).
 */
final class PriceResolverTest extends TestCase
{
    public function testWholesalerNeverPaysMoreForMoreUnits(): void
    {
        // регресія: опт від 1 шт. 80 грн, загальний поріг від 10 шт. 90 грн → оптовик за 10 шт. платив 90, за 9 — 80
        $variant = self::variant(10_000);
        $variant->addPriceTier('10', Money::of(9_000, 'UAH'));
        $wholesale = self::group(1, 'wholesale');
        $variant->addPriceTier('1', Money::of(8_000, 'UAH'), $wholesale);

        $resolver = new PriceResolver();
        self::assertSame(8_000, $resolver->unitPrice($variant, '9', $wholesale)->amount);
        self::assertSame(8_000, $resolver->unitPrice($variant, '10', $wholesale)->amount, 'Загальний поріг від 10 не перебиває груповий');
        self::assertSame(8_000, $resolver->unitPrice($variant, '100', $wholesale)->amount);
        self::assertSame(10_000, $resolver->unitPrice($variant, '9')->amount, 'Гість групових порогів не бачить');
        self::assertSame(9_000, $resolver->unitPrice($variant, '10')->amount);
    }

    public function testGroupWithOwnTiersIgnoresGeneralTiersEvenBelowItsThreshold(): void
    {
        $variant = self::variant(10_000);
        $variant->addPriceTier('3', Money::of(9_000, 'UAH'));
        $dealer = self::group(2, 'dealer');
        $variant->addPriceTier('5', Money::of(7_000, 'UAH'), $dealer);
        $variant->addPriceTier('20', Money::of(6_000, 'UAH'), $dealer);

        $resolver = new PriceResolver();
        self::assertSame(10_000, $resolver->unitPrice($variant, '4', $dealer)->amount, 'До свого порога — базова ціна, не загальний поріг від 3');
        self::assertSame(7_000, $resolver->unitPrice($variant, '5', $dealer)->amount);
        self::assertSame(7_000, $resolver->unitPrice($variant, '19', $dealer)->amount);
        self::assertSame(6_000, $resolver->unitPrice($variant, '20', $dealer)->amount, 'Серед групових — найбільший minQuantity ≤ qty');
    }

    public function testGroupWithoutOwnTiersFallsBackToGeneralTiers(): void
    {
        $variant = self::variant(10_000);
        $variant->addPriceTier('5', Money::of(9_000, 'UAH'));
        $dealer = self::group(2, 'dealer');
        $variant->addPriceTier('1', Money::of(8_000, 'UAH'), self::group(3, 'vip'));

        $resolver = new PriceResolver();
        self::assertSame(10_000, $resolver->unitPrice($variant, '1', $dealer)->amount, 'Пороги іншої групи не діють');
        self::assertSame(9_000, $resolver->unitPrice($variant, '5', $dealer)->amount, 'Без своїх порогів група бачить загальні');
    }

    public function testGroupsAreComparedByIdNotByInstance(): void
    {
        $variant = self::variant(10_000);
        $variant->addPriceTier('1', Money::of(8_000, 'UAH'), self::group(7, 'wholesale'));

        $resolver = new PriceResolver();
        self::assertSame(8_000, $resolver->unitPrice($variant, '1', self::group(7, 'wholesale'))->amount, 'Інший екземпляр з тим самим id (після $em->clear())');
        self::assertSame(10_000, $resolver->unitPrice($variant, '1', self::group(8, 'wholesale'))->amount, 'Той самий код, інший id — інша група');

        $unsaved = new CustomerGroup('draft', 'Чернетка');
        $variant->addPriceTier('1', Money::of(5_000, 'UAH'), $unsaved);
        self::assertSame(5_000, $resolver->unitPrice($variant, '1', $unsaved)->amount, 'Незбережена група — за ідентичністю');
        self::assertSame(10_000, $resolver->unitPrice($variant, '1', new CustomerGroup('draft', 'Чернетка'))->amount);
    }

    public function testDuplicateTierForSameGroupIsRejectedById(): void
    {
        $variant = self::variant(10_000);
        $variant->addPriceTier('5', Money::of(9_000, 'UAH'), self::group(7, 'wholesale'));
        $variant->addPriceTier('5', Money::of(9_500, 'UAH'));

        $this->expectException(CatalogInvariantViolation::class);
        $variant->addPriceTier('5', Money::of(8_000, 'UAH'), self::group(7, 'wholesale'));
    }

    private static function variant(int $priceMinor): ProductVariant
    {
        $variant = (new Product('T-1', 'UAH'))->getDefaultVariant();
        $variant->setPrice(Money::of($priceMinor, 'UAH'));

        return $variant;
    }

    private static function group(int $id, string $code): CustomerGroup
    {
        $group = new CustomerGroup($code, ucfirst($code));
        (new ReflectionProperty(CustomerGroup::class, 'id'))->setValue($group, $id);

        return $group;
    }
}
