<?php

declare(strict_types=1);

namespace App\Tests\Core\Pricing;

use App\Core\Catalog\Entity\Brand;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\DiscountResolver;
use App\Core\Pricing\Promotion\ArrayPromotionProvider;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Правила акцій (Ф3): цілі (категорія з предками, бренд, товар, весь каталог), дати, пріоритет, без сумування за замовчуванням, stackable. */
final class DiscountResolverTest extends TestCase
{
    private Category $shoes;
    private Category $running;
    private Brand $nike;
    private ProductVariant $nikeRunning;   // категорія running (дитина shoes), бренд Nike, 1000.00
    private ProductVariant $pumaBag;       // категорія bags, бренд Puma, 500.00

    protected function setUp(): void
    {
        $this->shoes = new Category();
        $sneakers = new Category($this->shoes);
        $this->running = new Category($sneakers);
        $bags = new Category();
        $this->nike = new Brand('Nike', 'nike');
        $puma = new Brand('Puma', 'puma');
        $p1 = new Product('P1', 'UAH');
        $p1->addCategory($this->running);
        $p1->setBrand($this->nike);
        $p1->getDefaultVariant()->setPrice(Money::of(100_000, 'UAH'));
        $this->nikeRunning = $p1->getDefaultVariant();
        $p2 = new Product('P2', 'UAH');
        $p2->addCategory($bags);
        $p2->setBrand($puma);
        $p2->getDefaultVariant()->setPrice(Money::of(50_000, 'UAH'));
        $this->pumaBag = $p2->getDefaultVariant();
    }

    public function testTargetsIncludeCategoryAncestorsBrandProductAndWholeCatalog(): void
    {
        $onShoes = self::percent('Взуття −10 %', 10, wholeCatalog: false);
        $onShoes->addCategory($this->shoes);
        $quote = self::resolver([$onShoes])->quote($this->nikeRunning, Money::of(100_000, 'UAH'), null);
        self::assertSame(90_000, $quote->price->amount, 'Категорія-предок охоплює підкатегорії');
        self::assertSame(100_000, $quote->listPrice->amount);
        self::assertSame(10, $quote->discountPercent());
        self::assertSame('Взуття −10 %', $quote->promotionNames());
        self::assertFalse(self::resolver([$onShoes])->quote($this->pumaBag, Money::of(50_000, 'UAH'), null)->hasDiscount(), 'Сумка — не взуття');

        $onBrand = self::percent('Puma −20 %', 20, wholeCatalog: false);
        $onBrand->addBrand($this->pumaBag->getProduct()->getBrand() ?? $this->nike);
        self::assertSame(40_000, self::resolver([$onBrand])->quote($this->pumaBag, Money::of(50_000, 'UAH'), null)->price->amount);
        self::assertFalse(self::resolver([$onBrand])->quote($this->nikeRunning, Money::of(100_000, 'UAH'), null)->hasDiscount());

        $onProduct = self::percent('P1 −5 %', 5, wholeCatalog: false);
        $onProduct->addProduct($this->nikeRunning->getProduct());
        self::assertSame(95_000, self::resolver([$onProduct])->quote($this->nikeRunning, Money::of(100_000, 'UAH'), null)->price->amount);

        $everything = self::percent('Все −1 %', 1);
        self::assertSame(49_500, self::resolver([$everything])->quote($this->pumaBag, Money::of(50_000, 'UAH'), null)->price->amount, 'Прапорець «на весь каталог»');

        $orphan = self::percent('Ціль видалено −30 %', 30, wholeCatalog: false);
        $onBrandRemoved = self::percent('Puma −30 %', 30, wholeCatalog: false);
        $onBrandRemoved->addBrand($this->pumaBag->getProduct()->getBrand() ?? $this->nike);
        $onBrandRemoved->removeBrand($this->pumaBag->getProduct()->getBrand() ?? $this->nike);
        foreach ([$orphan, $onBrandRemoved] as $promotion) {
            self::assertFalse(self::resolver([$promotion])->quote($this->pumaBag, Money::of(50_000, 'UAH'), null)->hasDiscount(), 'Без цілей і без прапорця — ні на що, не на весь каталог');
        }
    }

    public function testDatesAndActivityAreRespected(): void
    {
        $now = new DateTimeImmutable('2026-09-28 12:00:00');
        $future = self::percent('Завтра', 10);
        $future->setStartsAt($now->modify('+1 day'));
        $expired = self::percent('Учора', 10);
        $expired->setEndsAt($now->modify('-1 hour'));
        $inactive = self::percent('Вимкнена', 10);
        $inactive->setActive(false);
        $current = self::percent('Зараз', 10);
        $current->setStartsAt($now->modify('-1 day'));
        $current->setEndsAt($now->modify('+1 day'));

        $quote = self::resolver([$future, $expired, $inactive, $current])->quote($this->nikeRunning, Money::of(100_000, 'UAH'), null, $now);
        self::assertSame(['Зараз'], array_column($quote->promotions, 'name'));
    }

    public function testEndIsInclusiveUntilTheEndOfTheMinute(): void
    {
        $promotion = self::percent('До 23:59', 10);
        $promotion->setEndsAt(new DateTimeImmutable('2026-10-07 23:59'));
        self::assertSame('2026-10-07 23:59:59', $promotion->getEndsAt()?->format('Y-m-d H:i:s'), 'форма дає хвилини — зберігаємо до кінця хвилини');

        $resolver = self::resolver([$promotion]);
        $price = Money::of(100_000, 'UAH');
        self::assertTrue($resolver->quote($this->nikeRunning, $price, null, new DateTimeImmutable('2026-10-07 23:59:30'))->hasDiscount(), 'остання хвилина включно');
        self::assertTrue($resolver->quote($this->nikeRunning, $price, null, new DateTimeImmutable('2026-10-07 23:59:59'))->hasDiscount());
        self::assertFalse($resolver->quote($this->nikeRunning, $price, null, new DateTimeImmutable('2026-10-08 00:00:00'))->hasDiscount());
    }

    public function testOnlyBestPromotionAppliesUnlessStackable(): void
    {
        $small = self::percent('−5 %', 5);
        $big = self::percent('−30 %', 30);
        $prioritized = self::percent('Пріоритетна −10 %', 10);
        $prioritized->setPriority(10);

        $quote = self::resolver([$small, $big])->quote($this->nikeRunning, Money::of(100_000, 'UAH'), null);
        self::assertSame(70_000, $quote->price->amount, 'Рівний пріоритет — більша знижка, без сумування');
        self::assertCount(1, $quote->promotions);

        $quote = self::resolver([$small, $big, $prioritized])->quote($this->nikeRunning, Money::of(100_000, 'UAH'), null);
        self::assertSame(90_000, $quote->price->amount, 'Вищий пріоритет перемагає більшу знижку');

        $stackA = self::percent('Stack A −10 %', 10);
        $stackA->setStackable(true);
        $stackA->setPriority(5);
        $stackB = self::amount('Stack B −100', '100.00');
        $stackB->setStackable(true);
        $quote = self::resolver([$stackA, $stackB, $small])->quote($this->nikeRunning, Money::of(100_000, 'UAH'), null);
        self::assertSame(80_000, $quote->price->amount, '1000 − 10 % = 900, − 100 = 800; не-stackable −5 % не додається');
        self::assertSame(['Stack A −10 %', 'Stack B −100'], array_column($quote->promotions, 'name'));

        $quote = self::resolver([$big, $stackA])->quote($this->nikeRunning, Money::of(100_000, 'UAH'), null);
        self::assertSame(90_000, $quote->price->amount, 'Пріоритетна stackable перемагає; не-stackable −30 % до неї не додається');
        $plainStack = self::percent('Stack C −10 %', 10);
        $plainStack->setStackable(true);
        $quote = self::resolver([$big, $plainStack])->quote($this->nikeRunning, Money::of(100_000, 'UAH'), null);
        self::assertSame(70_000, $quote->price->amount, 'Переможець (більша знижка) не stackable — далі нічого не складається');
    }

    public function testPromotionAppliesToCustomerGroupOnlyWithExplicitPermission(): void
    {
        $wholesale = self::group(1, 'wholesale');
        $dealer = self::group(2, 'dealer');
        $retailOnly = self::percent('Роздріб −10 %', 10);
        $forWholesale = self::percent('Опт −5 %', 5);
        $forWholesale->addCustomerGroup($wholesale);
        $price = Money::of(100_000, 'UAH');

        self::assertSame(90_000, self::resolver([$retailOnly])->quote($this->nikeRunning, $price, null)->price->amount, 'Гість: акція без груп діє');
        self::assertFalse(self::resolver([$retailOnly])->quote($this->nikeRunning, $price, $wholesale)->hasDiscount(), 'Порожні групи = лише роздріб, оптова ціна не чіпається');
        self::assertSame(95_000, self::resolver([$forWholesale])->quote($this->nikeRunning, $price, null)->price->amount, 'Акція з групою діє і на роздріб');
        self::assertSame(95_000, self::resolver([$forWholesale])->quote($this->nikeRunning, $price, $wholesale)->price->amount, 'Дозволена група');
        self::assertSame(95_000, self::resolver([$forWholesale])->quote($this->nikeRunning, $price, self::group(1, 'wholesale'))->price->amount, 'Порівняння групи за id, не за екземпляром');
        self::assertFalse(self::resolver([$forWholesale])->quote($this->nikeRunning, $price, $dealer)->hasDiscount(), 'Інша група — ні');

        $quote = self::resolver([$retailOnly, $forWholesale])->quote($this->nikeRunning, $price, $wholesale);
        self::assertSame(['Опт −5 %'], array_column($quote->promotions, 'name'), 'Серед кількох для оптовика береться лише дозволена, хоч роздрібна і більша');

        $forWholesale->removeCustomerGroup($wholesale);
        self::assertFalse($forWholesale->allowsGroup($wholesale));
        self::assertTrue($forWholesale->allowsGroup(null));
    }

    public function testAmountIsCappedAndForeignCurrencyIgnored(): void
    {
        $huge = self::amount('−9999', '9999.00');
        $quote = self::resolver([$huge])->quote($this->pumaBag, Money::of(50_000, 'UAH'), null);
        self::assertSame(0, $quote->price->amount, 'Знижка не більша за ціну');
        self::assertSame(100, $quote->discountPercent());

        $usd = self::amount('−$10', '10.00', 'USD');
        self::assertFalse(self::resolver([$usd])->quote($this->pumaBag, Money::of(50_000, 'UAH'), null)->hasDiscount(), 'Сума в іншій валюті не застосовується');
        self::assertSame('−15 %', self::percent('x', 15)->getSummary());
        self::assertSame('−12.5 %', self::percent('x', 12.5)->getSummary());
        self::assertSame("−10,00\u{a0}USD", $usd->getSummary());
    }

    private static function percent(string $name, float $percent, bool $wholeCatalog = true): Promotion
    {
        $promotion = new Promotion($name, 'UAH');
        $promotion->setPercentDiscount((string) $percent);
        $promotion->setWholeCatalog($wholeCatalog);

        return $promotion;
    }

    private static function amount(string $name, string $amount, string $currency = 'UAH'): Promotion
    {
        $promotion = new Promotion($name, $currency);
        $promotion->setAmountDiscount($amount);
        $promotion->setWholeCatalog(true);

        return $promotion;
    }

    private static function group(int $id, string $code): CustomerGroup
    {
        $group = new CustomerGroup($code, ucfirst($code));
        (new ReflectionProperty(CustomerGroup::class, 'id'))->setValue($group, $id);

        return $group;
    }

    /** @param list<Promotion> $promotions */
    private static function resolver(array $promotions): DiscountResolver
    {
        return new DiscountResolver(new ArrayPromotionProvider($promotions));
    }
}
