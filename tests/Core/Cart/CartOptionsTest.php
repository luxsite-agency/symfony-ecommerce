<?php

declare(strict_types=1);

namespace App\Tests\Core\Cart;

use App\Core\Cart\CartCalculator;
use App\Core\Cart\Entity\Cart;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Tests\Core\Catalog\Option\DoorWithOptions;
use App\Tests\Core\Pricing\TestPricing;
use LogicException;
use PHPUnit\Framework\TestCase;

/** Кошик з опціями (ADR Rev 9): рядок = конфігурація, ціна перераховується калькулятором, проблеми опцій і компонентів. */
final class CartOptionsTest extends TestCase
{
    public function testConfigurationsAreSeparateLinesAndSameConfigurationMerges(): void
    {
        $fx = new DoorWithOptions();
        $options = TestPricing::options();
        $oak = $options->configure($fx->variant, '1', ['leaf' => 'oak'], 'uk');
        $pine = $options->configure($fx->variant, '1', ['leaf' => 'pine'], 'uk');

        $cart = new Cart(Cart::hashToken('o'), 'UAH');
        $cart->addItem($fx->variant, '1', $oak?->snapshot);
        $cart->addItem($fx->variant, '2', $pine?->snapshot);
        $cart->addItem($fx->variant, '1', $oak?->snapshot);
        self::assertCount(2, $cart->getItems());
        self::assertSame('2.000', $cart->findItem($fx->variant, $oak?->key)?->getQuantity());

        $totals = (new CartCalculator(TestPricing::create()))->calculate($cart, null);
        self::assertSame([115_000, 100_000], array_map(static fn ($l): int => $l->unitPrice->amount, $totals->lines));
        self::assertSame(2 * 115_000 + 2 * 100_000, $totals->itemsTotal->amount);
        self::assertSame([null, null], array_map(static fn ($l): ?string => $l->problem, $totals->lines));
    }

    public function testInvalidOptionsAndComponentStockAreLineProblems(): void
    {
        $fx = new DoorWithOptions();
        $options = TestPricing::options();
        $calculator = new CartCalculator(TestPricing::create());
        $cart = new Cart(Cart::hashToken('p'), 'UAH');
        $cart->addItem($fx->variant, '2', $options->configure($fx->variant, '2', ['leaf' => 'oak', 'trim' => 'kit'], 'uk')?->snapshot);

        self::assertSame(165_000, $calculator->calculate($cart, null)->lines[0]->unitPrice->amount, '1150 + лиштва 500');
        $fx->trim->setStock('4'); // треба 2,5 × 2 = 5
        self::assertSame('out_of_stock', $calculator->calculate($cart, null)->lines[0]->problem);

        $fx->trim->setStock('100');
        $fx->leaf->removeValue($fx->leaf->findValue('oak') ?? throw new LogicException());
        $line = $calculator->calculate($cart, null)->lines[0];
        self::assertSame('invalid_options', $line->problem);
        self::assertSame(100_000, $line->unitPrice->amount, 'ціна без опцій — рядок не можна замовити, але кошик показується');
    }

    public function testLineWithoutSnapshotUsesDefaultsLikePlaceOrder(): void
    {
        $fx = new DoorWithOptions();
        $fx->hinges->markDefault($fx->hinges->findValue('hidden') ?? throw new LogicException());
        $cart = new Cart(Cart::hashToken('n'), 'UAH');
        $cart->addItem($fx->variant, '1'); // лежав у кошику до того, як товару додали опції
        $calculator = new CartCalculator(TestPricing::create());
        self::assertSame(112_300, $calculator->calculate($cart, null)->lines[0]->unitPrice->amount, 'pine + hidden за замовчуванням, як порахує PlaceOrder');

        $fx->leaf->markDefault($fx->leaf->findValue('pine') ?? throw new LogicException(), false);
        self::assertSame('invalid_options', $calculator->calculate($cart, null)->lines[0]->problem, 'обов\'язкова група без default — обрати знову, а не «ок» у кошику і помилка на checkout');
    }

    public function testStockIsCheckedForTheWholeCartAcrossLines(): void
    {
        $fx = new DoorWithOptions();
        $options = TestPricing::options();
        $cart = new Cart(Cart::hashToken('s'), 'UAH');
        $cart->addItem($fx->variant, '2', $options->configure($fx->variant, '2', ['leaf' => 'oak', 'trim' => 'kit'], 'uk')?->snapshot);
        $cart->addItem($fx->variant, '2', $options->configure($fx->variant, '2', ['leaf' => 'pine', 'trim' => 'set'], 'uk')?->snapshot);
        $calculator = new CartCalculator(TestPricing::create());
        self::assertSame([null, null], self::problems($calculator, $cart));

        $fx->trim->setStock('9.5'); // кожен рядок окремо — 5, разом 10
        self::assertSame(['out_of_stock', 'out_of_stock'], self::problems($calculator, $cart));

        $fx->trim->setStock('100');
        $fx->variant->setStock('3'); // той самий варіант у двох рядках: 2 + 2
        self::assertSame(['out_of_stock', 'out_of_stock'], self::problems(new CartCalculator(TestPricing::create()), $cart));
    }

    public function testPromotionAppliesToOptionsButNotToComponents(): void
    {
        $fx = new DoorWithOptions();
        $door = new Promotion('Двері −10 %', 'UAH');
        $door->setPercentDiscount('10');
        $door->addProduct($fx->door);
        $trim = new Promotion('Лиштва −50 %', 'UAH');
        $trim->setPercentDiscount('50');
        $trim->addProduct($fx->trimProduct);
        $pricing = TestPricing::create([$door, $trim]);

        // S = 1000 + 15 % = 1150 → 1035; лиштва 2,5 × 200 = 500 без знижки (і без чужої акції на лиштву)
        $line = $pricing->configure($fx->variant, '1', ['leaf' => 'oak', 'trim' => 'kit'], 'uk', null);
        self::assertSame(153_500, $line->unitPrice()->amount);
        self::assertSame(165_000, $line->listPrice()?->amount);
        self::assertSame(['Двері −10 %'], array_column($line->quote->promotions, 'name'));

        $cart = new Cart(Cart::hashToken('d'), 'UAH');
        $cart->addItem($fx->variant, '1', $line->configuration?->snapshot);
        $cartLine = (new CartCalculator($pricing))->calculate($cart, null)->lines[0];
        self::assertSame(153_500, $cartLine->unitPrice->amount, 'кошик — те саме число, що форма на картці');
        self::assertSame(165_000, $cartLine->listPrice?->amount);
    }

    /** @return list<?string> */
    private static function problems(CartCalculator $calculator, Cart $cart): array
    {
        return array_map(static fn ($l): ?string => $l->problem, $calculator->calculate($cart, null)->lines);
    }
}
