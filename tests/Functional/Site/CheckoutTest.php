<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Order\Entity\Order;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\PaymentMethod;
use App\Core\Order\Enum\ShippingMethod;
use App\Core\Order\Mail\OrderMailer;
use App\Core\Order\Message\PushOrderToCrm;
use App\Core\Order\Message\SendCustomerOrderEmail;
use App\Core\Order\Message\SendShopOrderEmail;
use App\Core\Order\MessageHandler\SendCustomerOrderEmailHandler;
use App\Core\Order\MessageHandler\SendShopOrderEmailHandler;
use App\Core\Order\PlaceOrder;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Checkout і швидке замовлення без JS: замовлення, резерв залишку, outbox-повідомлення, «дякуємо» з purchase один раз, листи. */
final class CheckoutTest extends WebTestCase
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
        $this->product = new Product('CHK-1', 'UAH');
        $this->product->translate('uk')->setName('Двері checkout')->setSlug('dveri-checkout');
        $this->product->getDefaultVariant()->setPrice(Money::of(426_200, 'UAH'));
        $this->product->getDefaultVariant()->setStock('5');
        $this->product->getDefaultVariant()->addPriceTier('2', Money::of(400_000, 'UAH'));
        $this->em->persist($this->product);
        $this->em->flush();
        $settings = static::getContainer()->get(Settings::class);
        $settings->set(SettingKeys::TAGS_ON_DEV, '1');
        $settings->set(SettingKeys::ORDERS_NOTIFY_EMAIL, 'sales@example.test');
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(Settings::class)->invalidate();
        parent::tearDown();
    }

    public function testCheckoutFlowCreatesOrderReservesStockAndDispatchesOutbox(): void
    {
        // у кошик 2 шт → поріг «від 2» = 4000.00
        $crawler = $this->client->request('GET', $this->host.'/dveri-checkout');
        $this->client->submit($crawler->filter('form.add-to-cart')->form(['quantity' => '2']));
        $crawler = $this->client->followRedirect();
        $this->client->click($crawler->filter('a.btn:contains("Оформити замовлення")')->link());
        self::assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        self::assertStringContainsString("8\u{a0}000,00\u{a0}₴", $crawler->filter('.checkout-total')->text());
        $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('begin_checkout', $events[0]['event']);
        self::assertSame(8000.0, (float) $events[0]['ecommerce']['value']);

        // помилки валідації — 422 з підказками, замовлення нема
        $form = $crawler->filter('form.checkout')->form(['name' => '', 'phone' => '123']);
        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString("Вкажіть ім'я", $crawler->filter('.form-error')->text());
        self::assertSame(0, $this->em->getRepository(Order::class)->count([]));

        $key = (string) $crawler->filter('form.checkout input[name="key"]')->attr('value');
        $form = $crawler->filter('form.checkout')->form([
            'name' => 'Олена Петренко', 'phone' => '067 123 45 67', 'email' => 'olena@example.test', 'comment' => 'Подзвоніть після 18:00',
            'shipping' => 'nova_poshta', 'city' => 'Київ', 'address' => 'Відділення 12', 'payment' => 'cod',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $thanksUrl = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/order/[a-f0-9]{48}/thanks$#', $thanksUrl, 'Успішна сторінка — за opaque-токеном');

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->findOneBy(['email' => 'olena@example.test']);
        self::assertNotNull($order);
        self::assertMatchesRegularExpression('/^\d{4}-\d{6}$/', $order->getNumber(), 'YYMM-NNNNNN');
        self::assertSame('new', $order->getStatus());
        self::assertSame(OrderSource::Checkout, $order->getSource());
        self::assertSame('+380671234567', $order->getPhone(), 'Телефон нормалізовано');
        self::assertSame(ShippingMethod::NovaPoshta, $order->getShippingMethod());
        self::assertSame(PaymentMethod::CashOnDelivery, $order->getPaymentMethod());
        self::assertSame('Київ', $order->getShippingCity());
        self::assertSame(PlaceOrder::idempotencyKeyFor($key, '+380671234567'), $order->getIdempotencyKey(), 'Ключ збережено як sha256(ключ + телефон)');
        self::assertCount(1, $order->getItems());
        $item = $order->getItems()->first();
        self::assertNotFalse($item);
        self::assertSame('CHK-1', $item->getSku());
        self::assertSame('Двері checkout', $item->getName());
        self::assertSame(400_000, $item->getUnitPrice()->amount, 'Ціна порога «від 2»');
        self::assertSame(800_000, $order->getTotal()->amount);
        self::assertSame('2.000', $item->getReservedStock());
        self::assertSame('3.000', $this->em->getRepository(ProductVariant::class)->findOneBy(['sku' => 'CHK-1'])?->getStock(), 'Залишок списано атомарно');
        self::assertCount(1, $order->getHistory());

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $classes = array_map(static fn ($e): string => $e->getMessage()::class, $transport->getSent());
        self::assertContains(PushOrderToCrm::class, $classes, 'Outbox: push у CRM');
        self::assertContains(SendCustomerOrderEmail::class, $classes, 'Outbox: лист покупцю');
        self::assertContains(SendShopOrderEmail::class, $classes, 'Outbox: лист магазину — окреме повідомлення (п. 7)');

        // листи: покупцю (є email) і магазину (orders.notify_email)
        $mailer = static::getContainer()->get(OrderMailer::class);
        (new SendCustomerOrderEmailHandler($this->em, $mailer))(new SendCustomerOrderEmail((int) $order->getId()));
        (new SendShopOrderEmailHandler($this->em, $mailer))(new SendShopOrderEmail((int) $order->getId()));
        self::assertEmailCount(2);
        $customerEmail = self::getMailerMessage(0);
        self::assertNotNull($customerEmail);
        self::assertEmailHeaderSame($customerEmail, 'To', 'olena@example.test');
        self::assertEmailHtmlBodyContains($customerEmail, $order->getNumber());
        self::assertEmailHtmlBodyContains($customerEmail, 'Нова пошта');
        self::assertEmailHtmlBodyContains($customerEmail, 'узгодить менеджер');
        self::assertEmailTextBodyContains($customerEmail, $order->getNumber());
        self::assertEmailTextBodyContains($customerEmail, 'CHK-1');
        self::assertEmailHeaderSame(self::getMailerMessage(1) ?? $customerEmail, 'To', 'sales@example.test');

        // «дякуємо»: purchase один раз, кошик забуто
        $this->client->request('GET', $thanksUrl);
        self::assertResponseRedirects($this->host.'/order/thanks', 302, 'токен лишається в адресному рядку лише до першого показу');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Referrer-Policy', 'same-origin');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'), 'Не кешується браузером/проксі (ResponseHeaderBag додає private)');
        self::assertStringContainsString($order->getNumber(), $crawler->filter('h1')->text());
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 512, \JSON_THROW_ON_ERROR);
        $names = array_column($events, 'event');
        self::assertSame(['add_shipping_info', 'add_payment_info', 'purchase'], $names);
        self::assertSame($order->getNumber(), $events[2]['ecommerce']['transaction_id']);
        self::assertSame(8000.0, (float) $events[2]['ecommerce']['value']);
        self::assertSame('CHK-1', $events[2]['ecommerce']['items'][0]['item_id']);

        $this->client->request('GET', $thanksUrl);
        $crawler = $this->client->followRedirect();
        $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([], array_column($events, 'event'), 'Повторне відкриття — без purchase');
        $this->client->request('GET', $this->host.'/cart/');
        self::assertSelectorExists('.empty', 'Після оформлення кошик порожній');

        // ідемпотентність: той самий ключ ще раз → те саме замовлення, без другого списання
        $this->client->request('GET', $this->host.'/dveri-checkout');
        $this->client->submit($this->client->getCrawler()->filter('form.add-to-cart')->form(['quantity' => '1']));
        $crawler = $this->client->request('GET', $this->host.'/checkout/');
        $form = $crawler->filter('form.checkout')->form(['name' => 'Олена', 'phone' => '0671234567', 'key' => $key]);
        $this->client->submit($form);
        self::assertResponseRedirects($thanksUrl);
        self::assertSame(1, $this->em->getRepository(Order::class)->count([]));

        $this->client->request('GET', $this->host.'/order/'.str_repeat('0', 48).'/thanks');
        self::assertResponseStatusCodeSame(404);
    }

    public function testQuickOrderFromProductPage(): void
    {
        $crawler = $this->client->request('GET', $this->host.'/dveri-checkout');
        $form = $crawler->filter('form.quick-order')->form(['name' => 'Іван', 'phone' => '+380501112233', 'quantity' => '1']);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('менеджер зателефонує', $crawler->filter('.thanks-intro')->text());
        $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['purchase'], array_column($events, 'event'), 'Лише purchase: generate_lead — для форм без замовлення (рев\'ю #6 п. 3)');
        self::assertStringContainsString('узгодить менеджер', $crawler->filter('.shipping-note')->text());

        $order = $this->em->getRepository(Order::class)->findOneBy(['phone' => '+380501112233']);
        self::assertNotNull($order);
        self::assertSame(OrderSource::Quick, $order->getSource());
        self::assertSame(ShippingMethod::ManagerContact, $order->getShippingMethod());
        self::assertSame(PaymentMethod::ManagerContact, $order->getPaymentMethod());
        self::assertSame(426_200, $order->getTotal()->amount);

        // без імені — flash і назад на картку
        $crawler = $this->client->request('GET', $this->host.'/dveri-checkout');
        $this->client->submit($crawler->filter('form.quick-order')->form(['name' => '', 'phone' => '+380501112233']));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString("Вкажіть ім'я", $crawler->filter('.flash-error')->text());
    }

    public function testOutOfStockIsRejectedAtPlaceOrder(): void
    {
        $crawler = $this->client->request('GET', $this->host.'/dveri-checkout');
        $this->client->submit($crawler->filter('form.add-to-cart')->form(['quantity' => '5']));
        // між кошиком і checkout залишок зменшився
        $variant = $this->em->getRepository(ProductVariant::class)->findOneBy(['sku' => 'CHK-1']);
        self::assertNotNull($variant);
        $variant->setStock('4');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->host.'/checkout/');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form.checkout')->form(['name' => 'Іван', 'phone' => '0501112233']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em->getRepository(Order::class)->count([]));
        self::assertSame('4.000', $this->em->getRepository(ProductVariant::class)->findOneBy(['sku' => 'CHK-1'])?->getStock(), 'Нічого не списано');
    }
}
