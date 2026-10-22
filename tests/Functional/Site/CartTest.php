<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Cart\CartResolver;
use App\Core\Cart\Entity\Cart;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Кошик без JS (ADR D4/D11): форма на картці → cookie + DB-кошик → сторінка кошика, оновлення, видалення, JSON. */
final class CartTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $host;
    private Product $product;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->host = 'https://'.$host;
        $category = new Category();
        $category->translate('uk')->setName('Двері')->setSlug('dveri-cart');
        $this->product = new Product('CART-1', 'UAH');
        $this->product->translate('uk')->setName('Двері для кошика')->setSlug('dveri-dlia-koshyka');
        $this->product->getDefaultVariant()->setPrice(Money::of(426_200, 'UAH'));
        $this->product->getDefaultVariant()->setStock('5');
        $this->product->addCategory($category);
        $this->em->persist($category);
        $this->em->persist($this->product);
        $this->em->flush();
        // серверні події dataLayer виводяться лише з увімкненими тегами
        $settings = static::getContainer()->get(Settings::class);
        $settings->set(SettingKeys::TAGS_ON_DEV, '1');
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(Settings::class)->invalidate();
        parent::tearDown();
    }

    public function testAddUpdateRemoveWithoutJs(): void
    {
        $this->client->request('GET', $this->host.'/cart/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty', 'Кошик порожній');

        $crawler = $this->client->request('GET', $this->host.'/dveri-dlia-koshyka');
        $form = $crawler->filter('form.add-to-cart')->form(['quantity' => '2']);
        $this->client->submit($form);
        self::assertResponseRedirects($this->host.'/cart/');
        $cookie = $this->client->getCookieJar()->get(CartResolver::COOKIE);
        self::assertNotNull($cookie, 'Cookie кошика поставлено при першому додаванні');
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', strtolower((string) $cookie->getSameSite()));

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('додано в кошик', $crawler->filter('.flash-success')->text());
        self::assertCount(1, $crawler->filter('.cart-line'));
        self::assertSame('2', $crawler->filter('.cart-qty input[name="quantity"]')->attr('value'));
        self::assertStringContainsString("8\u{a0}524,00\u{a0}₴", $crawler->filter('.cart-total')->text());
        $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['add_to_cart', 'view_cart'], array_column($events, 'event'), 'add_to_cart з черги після редиректу + view_cart сторінки');
        self::assertSame('CART-1', $events[0]['ecommerce']['items'][0]['item_id']);
        self::assertSame(8524.0, (float) $events[0]['ecommerce']['value']);

        $cart = $this->em->getRepository(Cart::class)->findOneBy(['tokenHash' => Cart::hashToken($cookie->getValue())]);
        self::assertNotNull($cart, 'У БД — лише хеш токена');

        // те саме ще раз — зливається в один рядок
        $crawler = $this->client->request('GET', $this->host.'/dveri-dlia-koshyka');
        $this->client->submit($crawler->filter('form.add-to-cart')->form(['quantity' => '1']));
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('.cart-line'));
        self::assertSame('3', $crawler->filter('.cart-qty input[name="quantity"]')->attr('value'));

        // оновити кількість
        $this->client->submit($crawler->filter('form.cart-qty')->form(['quantity' => '4']));
        $crawler = $this->client->followRedirect();
        self::assertSame('4', $crawler->filter('.cart-qty input[name="quantity"]')->attr('value'));
        self::assertStringContainsString("17\u{a0}048,00", $crawler->filter('.cart-total')->text());

        // більше за залишок — проблема в рядку
        $this->client->submit($crawler->filter('form.cart-qty')->form(['quantity' => '9']));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Немає в наявності', $crawler->filter('.problem')->text());

        // JSON
        $this->client->request('GET', $this->host.'/cart.json');
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(1, $json['count']);
        self::assertSame('CART-1', $json['lines'][0]['sku']);
        self::assertSame('out_of_stock', $json['lines'][0]['problem']);
        self::assertSame('view_cart', $json['dataLayer']['event']);

        // прибрати
        $crawler = $this->client->request('GET', $this->host.'/cart/');
        $this->client->submit($crawler->filter('form[action$="/cart/remove"]')->form());
        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('.empty');
        $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['remove_from_cart'], array_column($events, 'event'));
    }

    /** Консиліум PR #6 п. 5: некратна кроку кількість з форми картки — flash-помилка, кошик порожній. */
    public function testQuantityStepIsRejectedWithoutJs(): void
    {
        $this->product->getDefaultVariant()->setQuantityRules('2', '0.5');
        $this->em->flush();
        $crawler = $this->client->request('GET', $this->host.'/dveri-dlia-koshyka');
        $this->client->submit($crawler->filter('form.add-to-cart')->form(['quantity' => '2.7']));
        self::assertResponseRedirects();
        $crawler = $this->client->request('GET', $this->host.'/cart/');
        self::assertStringContainsString('кратна 0.500', $crawler->filter('.flash-error')->text());
        self::assertSelectorExists('.empty');
    }

    public function testCsrfAndInactiveProductAreRejected(): void
    {
        $variantId = $this->product->getDefaultVariant()->getId();
        $this->client->request('POST', $this->host.'/cart/add', ['variant' => $variantId, 'quantity' => '1', '_token' => 'bad']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->client->getCookieJar()->get(CartResolver::COOKIE));

        $crawler = $this->client->request('GET', $this->host.'/dveri-dlia-koshyka');
        $token = (string) $crawler->filter('form.add-to-cart input[name="_token"]')->attr('value');
        // після запитів EM тесту скинуто (ресет сервісів при перезавантаженні ядра) — сутність перечитуємо
        $product = $this->em->find(Product::class, $this->product->getId());
        self::assertNotNull($product);
        $product->setActive(false);
        $this->em->flush();
        $this->client->request('POST', $this->host.'/cart/add', ['variant' => $variantId, 'quantity' => '1', '_token' => $token]);
        self::assertResponseRedirects();
        // редирект веде на referer (картка), яка вже 404 — flash доживає до наступної сторінки
        $crawler = $this->client->request('GET', $this->host.'/cart/');
        self::assertStringContainsString('недоступний', $crawler->filter('.flash-error')->text());
        self::assertSelectorExists('.empty');
    }
}
