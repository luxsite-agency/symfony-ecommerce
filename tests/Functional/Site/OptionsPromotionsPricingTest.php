<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Enum\NumberOptionRule;
use App\Core\Catalog\Enum\OptionSelection;
use App\Core\Order\Entity\Order;
use App\Core\Order\Entity\OrderItem;
use App\Core\Order\OrderExportFactory;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Наскрізний тест формули ціни рядка (консиліум 07.10.2026): B = 100 (тир 90 від 5 шт.), опції +10 % і +5, число
 * 2 × 2,50, компонент за ціною вибору 10, акції −10 % і −3 (обидві stackable), кількість 5.
 *   S = 90 + 9 + 5 + 5 = 109; Q = round(109 × 0,9) − 3 = 95,10; unit = 95,10 + 10 = 105,10; list = 109 + 10 = 119.
 * Однакове число на картці, у view_item, add_to_cart, кошику, view_cart, begin_checkout, OrderItem, снепшоті, CRM і purchase.
 */
final class OptionsPromotionsPricingTest extends WebTestCase
{
    private const int UNIT = 10_510;
    private const int LIST = 11_900;

    protected function tearDown(): void
    {
        static::getContainer()->get(Settings::class)->invalidate();
        parent::tearDown();
    }

    public function testSamePriceOnCardCartOrderSnapshotCrmAndDataLayer(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(3));
        [$product, $mount] = self::catalog($suffix);
        $em->persist($product);
        $em->persist($mount);
        $percent = new Promotion('Тиждень шаф', 'UAH');
        $percent->setPercentDiscount('10');
        $percent->setStackable(true);
        $percent->addProduct($product);
        $amount = new Promotion('Мінус 3', 'UAH');
        $amount->setAmountDiscount('3');
        $amount->setStackable(true);
        $amount->addProduct($product);
        $em->persist($percent);
        $em->persist($amount);
        $em->flush();
        static::getContainer()->get(Settings::class)->set(SettingKeys::TAGS_ON_DEV, '1');
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $url = 'https://'.$host.'/wardrobe-'.$suffix;

        // картка: «порахувати» на мінімальній кількості 5 → ціна з опціями й акцією, перекреслена — до акції
        $crawler = $client->request('GET', $url.'?calc=1&options[extras][]=lacquer&options[extras][]=handle&options[width]=2&options[mount]=kit');
        self::assertResponseIsSuccessful();
        self::assertSame('105.10', $crawler->filter('[data-options-price]')->attr('data-options-price'));
        self::assertStringContainsString(Money::of(self::LIST, 'UAH')->format('uk'), $crawler->filter('.price-with-options .old-price')->text());
        self::assertItem(self::event($crawler, 'view_item'), 'view_item');

        // у кошик: форма вже з обраними опціями
        $client->submit($crawler->filter('form.add-to-cart')->form(['quantity' => '5']));
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertItem(self::event($crawler, 'add_to_cart'), 'add_to_cart');
        self::assertItem(self::event($crawler, 'view_cart'), 'view_cart');
        $priceCell = $crawler->filter('.cart-line td')->eq(1);
        self::assertStringContainsString("105,10\u{a0}₴", $priceCell->text());
        self::assertStringContainsString('119,00', $priceCell->filter('.old-price')->text());
        self::assertStringContainsString('525,50', $crawler->filter('.cart-total')->text());

        // checkout
        $crawler = $client->request('GET', 'https://'.$host.'/checkout/');
        self::assertResponseIsSuccessful();
        self::assertItem(self::event($crawler, 'begin_checkout'), 'begin_checkout');
        $client->submit($crawler->filter('form.checkout')->form([
            'name' => 'Формула', 'phone' => '067 765 43 21', 'email' => 'formula-'.$suffix.'@example.test',
            'shipping' => 'nova_poshta', 'city' => 'Київ', 'address' => 'Відділення 1', 'payment' => 'cod',
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
        $thanks = $client->followRedirect();
        self::assertItem(self::event($thanks, 'purchase'), 'purchase');

        $em->clear();
        $order = $em->getRepository(Order::class)->findOneBy(['email' => 'formula-'.$suffix.'@example.test']);
        self::assertNotNull($order);
        /** @var list<OrderItem> $items */
        $items = $order->getItems()->toArray();
        self::assertCount(2, $items, 'головний рядок + компонент');
        [$main, $child] = $items;
        self::assertSame(self::UNIT, $main->getUnitPrice()->amount);
        self::assertSame(self::LIST, $main->getListPrice()?->amount);
        self::assertSame('Тиждень шаф, Мінус 3', $main->getPromotionName());
        $pricing = $main->getOptionsSnapshot()['pricing'] ?? [];
        self::assertEquals( // порядок ключів JSON MySQL не зберігає
            ['baseMinor' => 9_000, 'modifiersMinor' => 1_400, 'numberMinor' => 500, 'subjectMinor' => 10_900, 'discountMinor' => 1_390, 'componentsMinor' => 1_000, 'listMinor' => self::LIST, 'unitPriceMinor' => self::UNIT],
            array_intersect_key($pricing, array_flip(['baseMinor', 'modifiersMinor', 'numberMinor', 'subjectMinor', 'discountMinor', 'componentsMinor', 'listMinor', 'unitPriceMinor'])),
        );
        self::assertSame($main, $child->getParent());
        self::assertSame(0, $child->getUnitPrice()->amount, 'компонент уже в ціні батьківського рядка');
        self::assertSame('5.000', $child->getQuantity());
        self::assertSame(5 * self::UNIT, $order->getItemsTotal()->amount);

        // CRM: головний рядок і компонент з ціною 0 (резерв SKU), сума товарів = платіж
        $export = static::getContainer()->get(OrderExportFactory::class)->fromOrder($order);
        self::assertSame([self::UNIT, 0], array_column($export->items, 'unitPriceMinor'));
        self::assertSame(['5.000', '5.000'], array_column($export->items, 'quantity'));
        self::assertSame($export->itemsTotalMinor, array_sum(array_column($export->items, 'lineTotalMinor')));
    }

    /** @return array{Product, Product} шафа з опціями і компонент «монтаж» */
    private static function catalog(string $suffix): array
    {
        $mount = new Product('MOUNT-'.$suffix, 'UAH');
        $mount->translate('uk')->setName('Монтаж')->setSlug('mount-'.$suffix);
        $mount->getDefaultVariant()->setPrice(Money::of(5_000, 'UAH'));
        $mount->getDefaultVariant()->setStock('100');

        $product = new Product('WARDROBE-'.$suffix, 'UAH');
        $product->translate('uk')->setName('Шафа')->setSlug('wardrobe-'.$suffix);
        $variant = $product->getDefaultVariant();
        $variant->setPrice(Money::of(10_000, 'UAH'));
        $variant->addPriceTier('5', Money::of(9_000, 'UAH'));
        $variant->setQuantityRules('5', '1');
        $variant->setStock('100');
        $extras = $product->addOptionGroup('extras', OptionSelection::Multiple);
        $extras->addPercentModifier('lacquer', 1_000);
        $extras->addAbsoluteModifier('handle', Money::of(500, 'UAH'));
        $product->addOptionGroup('width')->configureNumber('1', '10', '1', NumberOptionRule::PerUnit, Money::of(250, 'UAH'), 'м');
        $product->addOptionGroup('mount')->addComponent('kit', $mount->getDefaultVariant(), '1', Money::of(1_000, 'UAH'));

        return [$product, $mount];
    }

    /** @return array<string, mixed> перший item події з dataLayer сторінки */
    private static function event(Crawler $crawler, string $name): array
    {
        $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 512, \JSON_THROW_ON_ERROR);
        $event = array_values(array_filter($events, static fn (array $e): bool => $name === $e['event']))[0] ?? null;
        self::assertNotNull($event, $name);

        return $event['ecommerce']['items'][0];
    }

    /** @param array<string, mixed> $item */
    private static function assertItem(array $item, string $event): void
    {
        self::assertSame(105.1, (float) $item['price'], $event.': price — до сплати за одиницю');
        self::assertSame(13.9, (float) ($item['discount'] ?? 0), $event.': discount — 119 − 105,10');
    }
}
