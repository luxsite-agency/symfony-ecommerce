<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Analytics\ConsentState;
use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Enum\AttributeType;
use App\Core\Order\Entity\Order;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Meta Pixel ecommerce: ViewContent (SKU обраного варіанта, ціна сторінки), AddToCart, InitiateCheckout, Purchase один
 * раз (event_id = номер замовлення); без згоди — нічого; GTM-режим (id пікселя порожній) — подій fbq нема, лише dataLayer.
 */
final class MetaPixelEventsTest extends WebTestCase
{
    private const string PIXEL = '123456789012';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Settings $settings;
    private string $host;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->settings = static::getContainer()->get(Settings::class);
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->host = 'https://'.$host;
        $this->settings->invalidate();
        $this->settings->set(SettingKeys::TAGS_ON_DEV, '1');
        $this->settings->set(SettingKeys::FB_PIXEL_ID, self::PIXEL);
        $this->settings->set(SettingKeys::GTM_CONTAINER_ID, null);
        $this->settings->set(SettingKeys::CONSENT_GRANTED_LOCALES, 'uk'); // так і за замовчуванням (services.yaml), явно для читача
    }

    protected function tearDown(): void
    {
        $this->settings->invalidate();
        parent::tearDown();
    }

    public function testViewContentUsesSelectedVariantSkuAndPagePrice(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $color = new Attribute('color_'.$suffix, AttributeType::Select);
        $color->setVariantAxis(true);
        $this->em->persist($color);
        $white = $color->addValue('white');
        $white->translate('uk')->setValue('Білий');
        $black = $color->addValue('black');
        $black->translate('uk')->setValue('Чорний');
        $product = new Product('MPV-'.$suffix, 'UAH');
        $product->translate('uk')->setName('Двері піксель')->setSlug('mpv-'.$suffix);
        $first = $product->getDefaultVariant();
        $first->setAxisValue($white);
        $first->setPrice(Money::of(420_000, 'UAH'));
        $first->setStock('3');
        $second = $product->addVariant('MPV-'.$suffix.'-B');
        $second->setAxisValue($black);
        $second->setPrice(Money::of(440_000, 'UAH'));
        $second->setStock('3');
        $this->em->persist($product);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->host.'/mpv-'.$suffix.'?color_'.$suffix.'=black');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(Money::of(440_000, 'UAH')->format('uk'), $crawler->filter('.buy-box p.price')->text(), 'На сторінці — ціна обраного варіанта');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("fbq('init','".self::PIXEL."')", $html);
        self::assertStringContainsString("fbq('track','PageView')", $html);
        self::assertCount(1, $crawler->filter('script[data-meta-pixel-events]'), 'Відправник подій пікселя на місці');

        $meta = self::meta($crawler);
        self::assertSame(['ViewContent'], array_column($meta, 'event'));
        self::assertEquals([ // JSON віддає 4400.0 як 4400
            'content_ids' => ['MPV-'.$suffix.'-B'],
            'content_type' => 'product',
            'contents' => [['id' => 'MPV-'.$suffix.'-B', 'quantity' => 1, 'item_price' => 4400.0]],
            'currency' => 'UAH',
            'value' => 4400.0,
        ], $meta[0]['params'], 'content_ids = SKU варіанта (g:id фіду), value = ціна сторінки');
        self::assertNotSame('', $meta[0]['event_id']);
    }

    public function testAddToCartInitiateCheckoutAndPurchaseOnce(): void
    {
        $product = new Product('MP-1', 'UAH');
        $product->translate('uk')->setName('Двері MP')->setSlug('dveri-mp');
        $product->getDefaultVariant()->setPrice(Money::of(100_000, 'UAH'));
        $product->getDefaultVariant()->setStock('10');
        $this->em->persist($product);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->host.'/dveri-mp');
        $viewContentId = self::meta($crawler)[0]['event_id'];
        $this->client->submit($crawler->filter('form.add-to-cart')->form(['quantity' => '2']));
        $crawler = $this->client->followRedirect();
        $meta = self::meta($crawler);
        self::assertSame(['AddToCart'], array_column($meta, 'event'), 'AddToCart з черги після редиректу; view_cart у Meta не йде');
        self::assertSame(['MP-1'], $meta[0]['params']['content_ids']);
        self::assertEquals([['id' => 'MP-1', 'quantity' => 2, 'item_price' => 1000.0]], $meta[0]['params']['contents']);
        self::assertSame(2000.0, (float) $meta[0]['params']['value']);
        self::assertSame('UAH', $meta[0]['params']['currency']);
        self::assertNotSame($viewContentId, $meta[0]['event_id']);

        $crawler = $this->client->request('GET', $this->host.'/checkout/');
        self::assertResponseIsSuccessful();
        $meta = self::meta($crawler);
        self::assertSame(['InitiateCheckout'], array_column($meta, 'event'));
        self::assertSame(2000.0, (float) $meta[0]['params']['value']);
        self::assertSame(2, $meta[0]['params']['num_items']);

        // відмова оформлення (помилки форми) — без Purchase
        $crawler = $this->client->submit($crawler->filter('form.checkout')->form(['name' => '', 'phone' => '123']));
        self::assertResponseStatusCodeSame(422);
        self::assertNotContains('Purchase', array_column(self::meta($crawler), 'event'));

        $this->client->submit($crawler->filter('form.checkout')->form([
            'name' => 'Піксель', 'phone' => '067 111 22 33', 'email' => 'pixel@example.test',
            'shipping' => 'nova_poshta', 'city' => 'Київ', 'address' => 'Відділення 1', 'payment' => 'cod',
        ]));
        self::assertResponseRedirects();
        $thanksUrl = (string) $this->client->getResponse()->headers->get('Location');
        $this->client->followRedirect();
        $crawler = $this->client->followRedirect();
        $order = $this->em->getRepository(Order::class)->findOneBy(['email' => 'pixel@example.test']);
        self::assertNotNull($order);

        $meta = self::meta($crawler);
        self::assertSame(['Purchase'], array_column($meta, 'event'), 'add_shipping_info/add_payment_info у Meta не йдуть');
        self::assertSame($order->getNumber(), $meta[0]['event_id'], 'event_id = номер замовлення (дедуплікація з Conversions API)');
        self::assertSame($order->getItemsTotal()->amount / 100.0, (float) $meta[0]['params']['value'], 'value = сума замовлення, як purchase GA4');
        self::assertSame(2000.0, (float) $meta[0]['params']['value']);
        self::assertSame(['MP-1'], $meta[0]['params']['content_ids']);
        self::assertSame(2, $meta[0]['params']['num_items']);
        self::assertSame('UAH', $meta[0]['params']['currency']);

        $this->client->request('GET', $thanksUrl);
        $crawler = $this->client->followRedirect();
        self::assertSame([], self::meta($crawler), 'Повторне відкриття «дякуємо» — без другого Purchase');
    }

    public function testNothingIsSentWithoutConsent(): void
    {
        $product = new Product('MPC-1', 'UAH');
        $product->translate('uk')->setName('Двері згода')->setSlug('dveri-mpc');
        $product->getDefaultVariant()->setPrice(Money::of(100_000, 'UAH'));
        $this->em->persist($product);
        $this->em->flush();

        // мова без згоди за замовчуванням (банер) і явна відмова в cookie на мові зі згодою
        $this->settings->set(SettingKeys::CONSENT_GRANTED_LOCALES, 'en'); // uk — без згоди за замовчуванням
        $crawler = $this->client->request('GET', $this->host.'/dveri-mpc');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#consent-banner'));
        self::assertNoPixel((string) $this->client->getResponse()->getContent());

        $this->settings->set(SettingKeys::CONSENT_GRANTED_LOCALES, 'uk');
        $this->client->getCookieJar()->set(new Cookie(ConsentState::COOKIE, 'denied', null, '/', parse_url($this->host, \PHP_URL_HOST) ?: ''));
        $this->client->request('GET', $this->host.'/dveri-mpc');
        self::assertResponseIsSuccessful();
        self::assertNoPixel((string) $this->client->getResponse()->getContent());
    }

    public function testGtmModeDoesNotDuplicateEvents(): void
    {
        $product = new Product('MPG-1', 'UAH');
        $product->translate('uk')->setName('Двері GTM')->setSlug('dveri-mpg');
        $product->getDefaultVariant()->setPrice(Money::of(100_000, 'UAH'));
        $this->em->persist($product);
        $this->em->flush();
        $this->settings->set(SettingKeys::FB_PIXEL_ID, null);
        $this->settings->set(SettingKeys::GTM_CONTAINER_ID, 'GTM-TEST123');

        $crawler = $this->client->request('GET', $this->host.'/dveri-mpg');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("'dataLayer','GTM-TEST123'", $html);
        self::assertNoPixel($html);
        $meta = self::meta($crawler);
        self::assertSame(['ViewContent'], array_column($meta, 'event'), 'Одна подія в dataLayer для тегу Meta в GTM');
        self::assertSame(['MPG-1'], $meta[0]['params']['content_ids']);
    }

    private static function assertNoPixel(string $html): void
    {
        foreach (['fbq(', 'connect.facebook.net', 'facebook.com/tr', 'data-meta-pixel-events'] as $needle) {
            self::assertStringNotContainsString($needle, $html, $needle);
        }
    }

    /** @return list<array{event: string, event_id: string, params: array<string, mixed>}> meta_pixel подій dataLayer сторінки */
    private static function meta(Crawler $crawler): array
    {
        /** @var list<array<string, mixed>> $events */
        $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 512, \JSON_THROW_ON_ERROR);
        $meta = [];
        foreach ($events as $event) {
            if (isset($event['meta_pixel'])) {
                /** @var array{event: string, event_id: string, params: array<string, mixed>} $m */
                $m = $event['meta_pixel'];
                $meta[] = $m;
            }
        }

        return $meta;
    }
}
