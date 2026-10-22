<?php

declare(strict_types=1);

namespace App\Tests\Core\Pricing;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Option\OptionPriceCalculator;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\DiscountResolver;
use App\Core\Pricing\PriceResolver;
use App\Core\Pricing\Pricing;
use App\Core\Pricing\Promotion\ArrayPromotionProvider;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Shared\Money\Money;
use App\Tests\Core\Catalog\Option\DoorWithOptions;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** Фасад ціни: мова підписів снепшота рядка — з контексту запиту, не захардкоджена (рев'ю PR #15 v2, п. 6). */
final class PricingTest extends TestCase
{
    public function testLineLocaleComesFromRequestThenDefault(): void
    {
        $fx = new DoorWithOptions();
        $fx->leaf->translate('en')->setName('Leaf');

        $requests = new RequestStack();
        $request = new Request();
        $request->setLocale('en');
        $requests->push($request);
        $line = self::pricing($requests, 'ru')->line($fx->variant, '1', null, null);
        self::assertSame('en', $line->configuration?->snapshot['locale']);
        self::assertSame('Leaf', $line->configuration->snapshot['groups'][0]['label'] ?? null);

        self::assertSame('ru', self::pricing(new RequestStack(), 'ru')->line($fx->variant, '1', null, null)->configuration?->snapshot['locale'], 'без запиту (CLI) — мова магазину');
        self::assertSame('uk', self::pricing($requests, 'ru')->line($fx->variant, '1', null, null, 'uk')->configuration?->snapshot['locale'], 'явна мова (PlaceOrder) — пріоритет');

        // збережений снепшот перераховується мовою сторінки
        $uk = self::pricing(new RequestStack(), 'uk')->line($fx->variant, '1', null, null)->configuration?->snapshot;
        self::assertSame('en', self::pricing($requests, 'uk')->line($fx->variant, '1', $uk, null)->configuration?->snapshot['locale']);
    }

    /**
     * Акції × групи (рев'ю #50, п. B): акція без груп діє на роздрібну ціну, тобто коли не спрацював поріг групи, навіть для
     * покупця в групі; на групову ціну — лише з явним дозволом групи. Те саме через опції (OptionPriceCalculator).
     */
    public function testPromotionsSeeGroupOnlyWhenGroupTierApplied(): void
    {
        $wholesale = self::group(1, 'wholesale');
        $withTier = (new Product('WT', 'UAH'))->getDefaultVariant();
        $withTier->setPrice(Money::of(100_000, 'UAH'));
        $withTier->addPriceTier('5', Money::of(80_000, 'UAH'), $wholesale);
        $noTier = (new Product('NT', 'UAH'))->getDefaultVariant();
        $noTier->setPrice(Money::of(100_000, 'UAH'));
        $retailPromo = new Promotion('Роздріб −20 %', 'UAH');
        $retailPromo->setPercentDiscount('20');
        $retailPromo->setWholeCatalog(true);

        $pricing = TestPricing::create([$retailPromo]);
        self::assertSame(80_000, $pricing->quote($noTier, '1', $wholesale)->price->amount, 'оптовик + товар без групового порога + акція без груп → акція діє');
        self::assertSame(80_000, $pricing->quote($withTier, '1', $wholesale)->price->amount, 'нижче свого порога — базова (роздрібна) ціна, акція діє');
        self::assertSame(80_000, $pricing->quote($withTier, '5', $wholesale)->price->amount, 'оптовик + груповий поріг + акція без груп → не діє');
        self::assertFalse($pricing->quote($withTier, '5', $wholesale)->hasDiscount());
        self::assertSame(80_000, $pricing->quote($noTier, '1', null)->price->amount, 'гість');

        $forWholesale = new Promotion('Опт −25 %', 'UAH');
        $forWholesale->setPercentDiscount('25');
        $forWholesale->setWholeCatalog(true);
        $forWholesale->addCustomerGroup($wholesale);
        $pricing = TestPricing::create([$retailPromo, $forWholesale]);
        self::assertSame(60_000, $pricing->quote($withTier, '5', $wholesale)->price->amount, 'акція з дозволеною групою + груповий поріг → діє на 800');
        self::assertSame(75_000, $pricing->quote($noTier, '1', $wholesale)->price->amount, 'без групового порога ціна роздрібна: більша з роздрібних акцій (−25 % дозволена і на роздріб)');

        // через опції: та сама ознака (ResolvedPrice::pricingGroup) для B з модифікаторами
        $fx = new DoorWithOptions();
        $fx->variant->addPriceTier('1', Money::of(90_000, 'UAH'), $wholesale);
        $pricing = TestPricing::create([$retailPromo]);
        $guest = $pricing->line($fx->variant, '1', null, null)->unitPrice()->amount;
        $wholesaler = $pricing->line($fx->variant, '1', null, $wholesale)->unitPrice()->amount;
        self::assertGreaterThan(0, $guest);
        self::assertSame($guest, $pricing->line($fx->variant, '1', null, self::group(2, 'dealer'))->unitPrice()->amount, 'група без порога — як гість, з акцією');
        self::assertNotSame($guest, $wholesaler);
        self::assertSame([], $pricing->line($fx->variant, '1', null, $wholesale)->quote->promotions, 'груповий поріг — роздрібна акція не лягає');
    }

    private static function group(int $id, string $code): CustomerGroup
    {
        $group = new CustomerGroup($code, ucfirst($code));
        (new ReflectionProperty(CustomerGroup::class, 'id'))->setValue($group, $id);

        return $group;
    }

    private static function pricing(RequestStack $requests, string $defaultLocale): Pricing
    {
        $discounts = new DiscountResolver(new ArrayPromotionProvider([]));

        return new Pricing(new PriceResolver(), $discounts, new OptionPriceCalculator(new PriceResolver(), $discounts), $requests, $defaultLocale);
    }
}
