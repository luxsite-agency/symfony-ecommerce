<?php

declare(strict_types=1);

namespace App\Tests\Core\Cart;

use App\Core\Cart\CartCalculator;
use App\Core\Cart\Entity\Cart;
use App\Core\Catalog\Entity\Brand;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\PriceResolver;
use App\Core\Pricing\Pricing;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Shared\Money\Money;
use App\Tests\Core\Pricing\TestPricing;
use PHPUnit\Framework\TestCase;

/** Кошик рахується щоразу: пороги цін за кількістю (Rev 7), сума рядків, проблеми наявності/мінімуму. */
final class CartCalculatorTest extends TestCase
{
    public function testPriceResolverPicksBestTierForQuantityAndGroup(): void
    {
        $variant = (new Product('T-1', 'UAH'))->getDefaultVariant();
        $variant->setPrice(Money::of(10_000, 'UAH'));
        $variant->addPriceTier('5', Money::of(9_000, 'UAH'));
        $variant->addPriceTier('10', Money::of(8_000, 'UAH'));
        $dealer = new CustomerGroup('dealer', 'Дилер');
        $variant->addPriceTier('5', Money::of(7_000, 'UAH'), $dealer);

        $resolver = new PriceResolver();
        self::assertSame(10_000, $resolver->unitPrice($variant, '1')->amount);
        self::assertSame(9_000, $resolver->unitPrice($variant, '5')->amount);
        self::assertSame(9_000, $resolver->unitPrice($variant, '9.5')->amount);
        self::assertSame(8_000, $resolver->unitPrice($variant, '10')->amount);
        self::assertSame(7_000, $resolver->unitPrice($variant, '6', $dealer)->amount, 'Поріг групи має перевагу');
        self::assertSame(7_000, $resolver->unitPrice($variant, '12', $dealer)->amount, 'У групи є свій поріг — загальний від 10 для неї не діє (PriceResolverTest)');
    }

    public function testTotalsAndProblems(): void
    {
        $door = new Product('D-1', 'UAH');
        $door->getDefaultVariant()->setPrice(Money::of(426_200, 'UAH'));
        $door->getDefaultVariant()->setStock('1');
        $tile = new Product('M-1', 'UAH');
        $tile->getDefaultVariant()->setPrice(Money::of(1_250, 'UAH'));
        $tile->getDefaultVariant()->setStock('100');
        $tile->getDefaultVariant()->setQuantityRules('2', '0.5');
        $hidden = new Product('H-1', 'UAH');
        $hidden->setActive(false);

        $cart = new Cart(Cart::hashToken('t'), 'UAH');
        $cart->addItem($door->getDefaultVariant(), '2');
        $cart->addItem($tile->getDefaultVariant(), '2.5');
        $cart->addItem($door->getDefaultVariant(), '1');
        // правила варіанта змінились після додавання — калькулятор показує проблему рядка
        $tile->getDefaultVariant()->setQuantityRules('3', '1');

        $totals = (new CartCalculator(self::pricing()))->calculate($cart, null);
        self::assertCount(2, $totals->lines, 'Той самий варіант злився в один рядок');
        self::assertSame('3.000', $totals->lines[0]->item->getQuantity());
        self::assertSame(1_278_600, $totals->lines[0]->lineTotal->amount);
        self::assertSame('out_of_stock', $totals->lines[0]->problem);
        self::assertSame(3_125, $totals->lines[1]->lineTotal->amount, '2.5 × 12.50');
        self::assertSame('below_min', $totals->lines[1]->problem);
        self::assertSame(1_281_725, $totals->itemsTotal->amount);
        self::assertTrue($totals->hasProblems());

        $this->expectException(CatalogInvariantViolation::class);
        $cart->addItem($hidden->getDefaultVariant(), '1');
    }

    /** Консиліум PR #6 п. 5: quantityStep — не лише minQuantity — перевіряється при додаванні, зміні й злитті рядків. */
    public function testQuantityStepIsEnforcedInCart(): void
    {
        $tile = new Product('M-2', 'UAH');
        $tile->getDefaultVariant()->setPrice(Money::of(1_250, 'UAH'));
        $tile->getDefaultVariant()->setStock('100');
        $tile->getDefaultVariant()->setQuantityRules('2', '0.5');
        $cart = new Cart(Cart::hashToken('t'), 'UAH');

        try {
            $cart->addItem($tile->getDefaultVariant(), '2.7');
            self::fail('2.7 при кроці 0.5 від 2 не проходить');
        } catch (CatalogInvariantViolation $e) {
            self::assertStringContainsString('кратна 0.500', $e->getMessage());
        }
        self::assertCount(0, $cart->getItems());

        $cart->addItem($tile->getDefaultVariant(), '2.5');
        $cart->addItem($tile->getDefaultVariant(), '0.5'); // злиття: 3.0 — кратно
        $item = $cart->getItems()->first();
        self::assertNotFalse($item);
        self::assertSame('3.000', $item->getQuantity());
        try {
            $cart->addItem($tile->getDefaultVariant(), '0.2'); // злиття дало б 3.2
            self::fail('Злиття в некратну кількість не проходить');
        } catch (CatalogInvariantViolation) {
        }
        self::assertSame('3.000', $item->getQuantity(), 'Рядок не змінився');
        try {
            $item->setQuantity('3.2');
            self::fail('setQuantity у некратну кількість не проходить');
        } catch (CatalogInvariantViolation) {
        }

        // крок змінився після додавання: (3 − 1) не кратне 1.5 → проблема рядка invalid_step
        $tile->getDefaultVariant()->setQuantityRules('1', '1.5');
        $totals = (new CartCalculator(self::pricing()))->calculate($cart, null);
        self::assertSame('invalid_step', $totals->lines[0]->problem);
        self::assertTrue($totals->hasProblems());
    }

    /** Ф3: акція на бренд знижує ціну рядка, listPrice — ціна до акції; інший бренд — без знижки. */
    public function testPromotionLowersLinePriceAndKeepsListPrice(): void
    {
        $brand = new Brand('Nike', 'nike');
        $door = new Product('D-P', 'UAH');
        $door->setBrand($brand);
        $door->getDefaultVariant()->setPrice(Money::of(100_000, 'UAH'));
        $door->getDefaultVariant()->setStock('10');
        $other = new Product('D-O', 'UAH');
        $other->getDefaultVariant()->setPrice(Money::of(50_000, 'UAH'));
        $other->getDefaultVariant()->setStock('10');
        $promotion = new Promotion('Nike −15 %', 'UAH');
        $promotion->setPercentDiscount('15');
        $promotion->addBrand($brand);

        $cart = new Cart(Cart::hashToken('t'), 'UAH');
        $cart->addItem($door->getDefaultVariant(), '2');
        $cart->addItem($other->getDefaultVariant(), '1');
        $totals = (new CartCalculator(self::pricing([$promotion])))->calculate($cart, null);

        self::assertSame(85_000, $totals->lines[0]->unitPrice->amount);
        self::assertSame(100_000, $totals->lines[0]->listPrice?->amount);
        self::assertSame('Nike −15 %', $totals->lines[0]->promotions[0]['name']);
        self::assertSame(170_000, $totals->lines[0]->lineTotal->amount);
        self::assertNull($totals->lines[1]->listPrice);
        self::assertSame(220_000, $totals->itemsTotal->amount);
    }

    /** @param list<Promotion> $promotions */
    private static function pricing(array $promotions = []): Pricing
    {
        return TestPricing::create($promotions);
    }

    public function testCurrencyMismatchIsRejected(): void
    {
        $cart = new Cart(Cart::hashToken('t'), 'UAH');
        $this->expectException(CatalogInvariantViolation::class);
        $cart->addItem((new Product('E-1', 'EUR'))->getDefaultVariant(), '1');
    }
}
