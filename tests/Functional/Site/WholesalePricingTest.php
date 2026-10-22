<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Enum\OptionSelection;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Order\Entity\Order;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use App\Site\Feed\MerchantFeedGenerator;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * E13 PR-4, ціни групи покупця (консиліум 07.10.2026, П3): залогінений оптовик бачить ціну своєї групи на картці,
 * у лістингу (накладка поверх кешу), у кошику, в dataLayer і в замовленні (customer_id + снепшот групи), поруч —
 * «роздріб»; роздрібна акція на нього не діє (група без дозволу). Гість, фід і schema Offer лишаються роздрібними.
 *
 * P1: V1 10.00 (опт 8.00), V2 12.00; акція «взуття −10 %» без груп: діє на роздрібну ціну, тобто й на оптовика там, де
 * для нього не спрацював груповий поріг (P2), і не лягає на оптову ціну (V1). P2 має опції (значення за замовчуванням
 * без доплати) — лічильник запитів лістингу ловить ліниве читання опцій у накладці.
 */
final class WholesalePricingTest extends CatalogPageTestCase
{
    private CustomerGroup $wholesale;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wholesale = new CustomerGroup('wholesale_'.$this->suffix, 'Опт');
        $this->fx->v['V1']->addPriceTier('1', Money::of(800, 'UAH'), $this->wholesale);
        foreach (['P2'] as $name) { // P1 без опцій: на ньому перевіряється форма швидкого замовлення
            $pack = $this->fx->p[$name]->addOptionGroup('pack', OptionSelection::Single);
            $pack->translate(self::defaultLocale())->setName('Пакування');
            $plain = $pack->addAbsoluteModifier('plain', Money::of(0, 'UAH'));
            $plain->translate(self::defaultLocale())->setLabel('Звичайне');
            $pack->markDefault($plain);
            $pack->addAbsoluteModifier('gift', Money::of(100, 'UAH'));
        }
        $promotion = new Promotion('Взуття −10 %', 'UAH');
        $promotion->setPercentDiscount('10');
        $promotion->addCategory($this->fx->shoes);
        $this->customer = new Customer('opt-'.$this->suffix.'@example.test', 'Оптовик', self::defaultLocale());
        $this->customer->verifyEmail(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->customer, 'correct horse battery'));
        $this->customer->setCustomerGroup($this->wholesale);
        foreach ([$this->wholesale, $promotion, $this->customer] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->reindex($this->fx->productIds());
        // dataLayer рендериться лише з увімкненими тегами
        $settings = static::getContainer()->get(Settings::class);
        $settings->invalidate();
        $settings->set(SettingKeys::TAGS_ON_DEV, '1');
        $settings->set(SettingKeys::GTM_CONTAINER_ID, 'GTM-TEST123');
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(Settings::class)->invalidate();
        parent::tearDown();
    }

    public function testGuestSeesRetailWithPromotionAndNoRetailLabel(): void
    {
        $locale = self::defaultLocale();
        $crawler = $this->get($this->productUrl($this->fx->p['P1'], $locale));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(Money::of(900, 'UAH')->format($locale), $crawler->filter('p.price[data-variant-id]')->text(), 'роздріб з акцією');
        self::assertCount(0, $crawler->filter('.retail-price'));
        self::assertEquals(9, self::event($crawler, 'view_item')['ecommerce']['items'][0]['price']);

        $crawler = $this->get($this->categoryUrl($this->fx->shoes, $locale));
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('li.card[data-product-id="'.$this->fx->id('P1').'"]');
        self::assertStringContainsString(Money::of(1000, 'UAH')->format($locale), $card->filter('.price')->text(), 'лістинг — з індексу, роздріб');
        self::assertCount(0, $card->filter('.retail-price'));
    }

    public function testWholesalerSeesGroupPriceEverywhereAndOrderKeepsGroup(): void
    {
        $locale = self::defaultLocale();
        // гостьовий лістинг двічі: перший прогріває кеш каталогу, другий — база запитів для порівняння з оптовиком
        $this->get($this->categoryUrl($this->fx->shoes, $locale));
        $this->client->enableProfiler();
        $this->get($this->categoryUrl($this->fx->shoes, $locale));
        self::assertResponseIsSuccessful();
        $guestQueries = $this->queryCount();
        $this->client->loginUser($this->customer, 'site');

        // картка: ціна групи, роздріб (з акцією) поруч, акція на групу не діє; dataLayer — ціна групи; schema Offer — роздріб
        $crawler = $this->get($this->productUrl($this->fx->p['P1'], $locale));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('private', (string) $this->client->getResponse()->headers->get('Cache-Control'), 'сторінка з оптовими цінами — не для спільного кешу');
        $price = $crawler->filter('p.price[data-variant-id]');
        self::assertStringContainsString(Money::of(800, 'UAH')->format($locale), $price->text());
        self::assertStringContainsString(Money::of(900, 'UAH')->format($locale), $price->filter('.retail-price')->text(), '«роздріб» поруч');
        self::assertCount(0, $price->filter('.old-price'), 'роздрібна акція на оптову ціну не лягає');
        self::assertEquals(8, self::event($crawler, 'view_item')['ecommerce']['items'][0]['price']);
        $offers = self::ld($crawler)['offers'] ?? [];
        self::assertSame('9.00', $offers['lowPrice'] ?? $offers['price'] ?? null, 'schema Offer — роздріб, навіть для залогіненого оптовика');
        self::assertStringNotContainsString(Money::of(800, 'UAH')->format($locale), $crawler->filter('title')->text(), 'SEO-мета (title з шаблону «{price}») — роздріб');
        $seen = (string) $crawler->filter('form.quick-order input[name="price['.$this->fx->v['V1']->getId().']"]')->attr('value');
        self::assertSame('800', $seen, 'швидке замовлення передає на звірку ціну групи');

        // товар без порога групи: ціна роздрібна, тож роздрібна акція діє і на оптовика; підпису «роздріб» нема
        $crawler = $this->get($this->productUrl($this->fx->p['P2'], $locale));
        self::assertResponseIsSuccessful();
        $price = $crawler->filter('p.price[data-variant-id]');
        self::assertCount(1, $price, mb_substr($crawler->filter('.product-info')->html(), 0, 1500));
        self::assertStringContainsString(Money::of(1350, 'UAH')->format($locale), $price->text(), '15.00 − 10 %');
        self::assertCount(1, $price->filter('.old-price'), $price->html());
        self::assertStringContainsString(Money::of(1500, 'UAH')->format($locale), $price->filter('.old-price')->text());
        self::assertCount(0, $price->filter('.retail-price'));

        // лістинг: ціна групи поверх кешу, роздріб поруч; view_item_list — ціна групи
        $this->client->enableProfiler();
        $crawler = $this->get($this->categoryUrl($this->fx->shoes, $locale));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('private', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $wholesalerQueries = $this->queryCount();
        $card = $crawler->filter('li.card[data-product-id="'.$this->fx->id('P1').'"]');
        self::assertStringContainsString(Money::of(800, 'UAH')->format($locale), $card->filter('.price')->text());
        self::assertStringContainsString(Money::of(900, 'UAH')->format($locale), $card->filter('.retail-price')->text(), '«роздріб» — з фасаду, з акцією');
        $p2 = $crawler->filter('li.card[data-product-id="'.$this->fx->id('P2').'"]');
        self::assertStringContainsString(Money::of(1350, 'UAH')->format($locale), $p2->filter('.price')->text(), 'без порога групи — роздрібна акція і для оптовика');
        self::assertCount(0, $p2->filter('.retail-price'), 'ціна і так роздрібна — без підпису');
        $listed = array_column(self::event($crawler, 'view_item_list')['ecommerce']['items'], 'price', 'item_id');
        self::assertEquals(8, $listed['P1-'.$this->suffix] ?? null);
        self::assertEquals(13.5, $listed['P2-'.$this->suffix] ?? null, 'без порога групи — роздрібна ціна з акцією');

        // накладка — пакетом: покупець, варіанти з порогами, опції, акції — 4 запити понад гостьову сторінку з тим самим
        // теплим кешем каталогу (гостьовий запит у testGuest… іде першим і прогріває його), без лінивих запитів на опції карток;
        // ще 2 — кошик покупця за customer_id (шапка) і його обране для кнопок на картках (E12 PR-3/PR-10)
        self::assertLessThanOrEqual($guestQueries + 6, $wholesalerQueries, 'лістинг оптовика: ≤ 6 запитів понад гостьовий');

        // фід — роздріб, оптова ціна туди не потрапляє
        $feed = (string) file_get_contents(static::getContainer()->get(MerchantFeedGenerator::class)->generate($locale)['path']);
        self::assertStringContainsString('<g:id>P1-'.$this->suffix.'</g:id>', $feed);
        self::assertStringContainsString('<g:price>10.00 UAH</g:price>', $feed, 'роздріб');
        self::assertStringNotContainsString('8.00 UAH', $feed);

        // кошик: ціна групи, роздріб поруч
        $this->client->request('POST', 'http://localhost/cart/add', ['variant' => (string) $this->fx->v['V1']->getId(), 'quantity' => '2', '_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        $line = $crawler->filter('tr.cart-line[data-sku="P1-'.$this->suffix.'"]');
        self::assertStringContainsString("8,00\u{a0}₴", $line->filter('td')->eq(1)->text());
        self::assertStringContainsString("9,00\u{a0}₴", $line->filter('.retail-price')->text());
        self::assertStringContainsString("16,00\u{a0}₴", $crawler->filter('.cart-total')->text());

        // checkout → замовлення з покупцем, снепшотом групи і ціною групи в рядку
        $crawler = $this->client->request('GET', 'http://localhost/checkout/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString("16,00\u{a0}₴", $crawler->filter('.checkout-total')->text());
        $this->client->submit($crawler->filter('form.checkout')->form([
            'name' => 'Оптовик', 'phone' => '0671234567', 'shipping' => 'nova_poshta', 'city' => 'Київ', 'address' => 'Відділення 1', 'payment' => 'cod',
        ]));
        self::assertResponseRedirects();
        $this->em()->clear();
        $order = $this->em()->getRepository(Order::class)->findOneBy(['phone' => '+380671234567'], ['id' => 'DESC']);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame($this->customer->getId(), $order->getCustomer()?->getId());
        self::assertSame('wholesale_'.$this->suffix, $order->getCustomerGroupCode());
        $item = $order->getItems()->first();
        self::assertNotFalse($item);
        self::assertSame(800, $item->getUnitPrice()->amount);
        self::assertSame(1600, $order->getItemsTotal()->amount);
    }

    public function testPromotionAllowedForGroupAppliesOnTopOfGroupPrice(): void
    {
        $locale = self::defaultLocale();
        $forWholesale = new Promotion('Опт −25 %', 'UAH');
        $forWholesale->setPercentDiscount('25');
        $forWholesale->addProduct($this->fx->p['P1']);
        $forWholesale->addCustomerGroup($this->wholesale);
        $this->em()->persist($forWholesale);
        $this->em()->flush();

        $this->client->loginUser($this->customer, 'site');
        $crawler = $this->get($this->productUrl($this->fx->p['P1'], $locale));
        $price = $crawler->filter('p.price[data-variant-id]');
        self::assertStringContainsString(Money::of(600, 'UAH')->format($locale), $price->text(), '8.00 − 25 %');
        self::assertStringContainsString(Money::of(800, 'UAH')->format($locale), $price->filter('.old-price')->text(), 'до акції — оптова');
    }

    private function queryCount(): int
    {
        return $this->collector()->getQueryCount();
    }

    private function collector(): DoctrineDataCollector
    {
        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector;
    }

    /** @return array<string, mixed> перша подія dataLayer з такою назвою */
    private static function event(Crawler $crawler, string $name): array
    {
        $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 512, \JSON_THROW_ON_ERROR);
        foreach (\is_array($events) ? $events : [] as $event) {
            if (($event['event'] ?? null) === $name) {
                return $event;
            }
        }
        self::fail('Подія '.$name.' не знайдена');
    }

    /** @return array<string, mixed> */
    private static function ld(Crawler $crawler): array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]')->each(static fn (Crawler $s): string => $s->text()) as $json) {
            $data = json_decode($json, true);
            if (\is_array($data) && 'Product' === ($data['@type'] ?? null)) {
                return $data;
            }
        }

        return [];
    }
}
