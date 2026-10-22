<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Order\Entity\Order;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;

/** Акції на сайті (Ф3): стара/нова ціна на картці й у кошику, discount у dataLayer, снепшот у замовленні після checkout. */
final class PromotionsSiteTest extends CatalogPageTestCase
{
    protected function tearDown(): void
    {
        static::getContainer()->get(Settings::class)->invalidate();
        parent::tearDown();
    }

    public function testOldAndNewPriceOnCardCartAndOrder(): void
    {
        $locale = static::defaultLocale();
        $promotion = new Promotion('Nike week', 'UAH');
        $promotion->setPercentDiscount('10');
        $promotion->addBrand($this->fx->nike);
        $this->em()->persist($promotion);
        $this->em()->flush();
        static::getContainer()->get(Settings::class)->set(SettingKeys::TAGS_ON_DEV, '1');

        // картка: V1 1000 → 900, перекреслена 1000
        $p1 = $this->fx->p['P1'];
        $crawler = $this->client->request('GET', $this->productUrl($p1, $locale));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(Money::of(900, 'UAH')->format($locale), $crawler->filter('.price')->text());
        self::assertStringContainsString(Money::of(1000, 'UAH')->format($locale), $crawler->filter('.price .old-price')->text());

        // Puma (P2) без акції — без перекресленої ціни
        $crawler2 = $this->client->request('GET', $this->productUrl($this->fx->p['P2'], $locale));
        self::assertCount(0, $crawler2->filter('.price .old-price'));

        // у кошик V1 → add_to_cart з discount, у кошику ціна 9.00 і перекреслена 10.00
        $crawler = $this->client->request('GET', $this->productUrl($p1, $locale));
        $form = $crawler->filter('form.add-to-cart')->form(['quantity' => '2']);
        // варіант обрано осями (типово — перший доступний, V1): форма кладе його прихованим полем, без дубля-select
        self::assertSame((string) $this->fx->v['V1']->getId(), $form['variant']->getValue());
        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $priceCell = $crawler->filter('.cart-line td')->eq(1);
        self::assertStringContainsString("9,00\u{a0}₴", $priceCell->text());
        self::assertStringContainsString('10,00', $priceCell->filter('.old-price')->text());
        self::assertStringContainsString('18,00', $crawler->filter('.cart-total')->text());
        $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 512, \JSON_THROW_ON_ERROR);
        $addToCart = array_values(array_filter($events, static fn (array $e): bool => 'add_to_cart' === $e['event']))[0] ?? null;
        self::assertNotNull($addToCart);
        self::assertSame(9.0, (float) $addToCart['ecommerce']['items'][0]['price']);
        self::assertSame(1.0, (float) $addToCart['ecommerce']['items'][0]['discount'], 'GA4 discount — знижка акції за одиницю');

        // checkout → снепшот у замовленні
        $crawler = $this->client->request('GET', $this->url('site_checkout', ['_locale' => $locale]));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form.checkout')->form(['name' => 'Марія', 'phone' => '0501112233', 'city' => 'Львів', 'address' => 'відділення 5']));
        self::assertResponseRedirects();
        $this->em()->clear();
        $order = $this->em()->getRepository(Order::class)->findOneBy(['phone' => '+380501112233']);
        self::assertNotNull($order);
        $item = $order->getItems()->first();
        self::assertNotFalse($item);
        self::assertSame(900, $item->getUnitPrice()->amount);
        self::assertSame(1000, $item->getListPrice()?->amount);
        self::assertSame('Nike week', $item->getPromotionName());
        self::assertSame(1800, $order->getTotal()->amount);

        // «дякуємо»: purchase з discount зі снепшота (посилання з токеном → 302 на адресу без токена)
        $this->client->followRedirect();
        $crawler = $this->client->followRedirect();
        $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 512, \JSON_THROW_ON_ERROR);
        $purchase = array_values(array_filter($events, static fn (array $e): bool => 'purchase' === $e['event']))[0] ?? null;
        self::assertNotNull($purchase);
        self::assertSame(1.0, (float) $purchase['ecommerce']['items'][0]['discount']);
        self::assertSame(18.0, (float) $purchase['ecommerce']['value']);
    }
}
