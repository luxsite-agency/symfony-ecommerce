<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site\Account;

use App\Core\Cart\CartResolver;
use App\Core\Cart\Entity\Cart;
use App\Core\Catalog\Entity\Product;
use App\Core\Customer\Entity\Customer;
use App\Core\Order\Entity\Order;
use App\Core\Order\Entity\OrderItem;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\PaymentMethod;
use App\Core\Order\Enum\ShippingMethod;
use App\Core\Order\OrderClaims;
use App\Core\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * E12 PR-2: історія замовлень у кабінеті лише своя (чуже — 404, IDOR), деталі, «повторити» в кошик за поточними
 * цінами з пропуском недоступних, прив'язка гостьових замовлень за підтвердженим email натисканням покупця
 * (лише точний збіг адреси, лише нічиї, лише підтверджений email), нове замовлення залогіненого — одразу в історії.
 */
final class OrderHistoryTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $site;
    private string $suffix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects(false);
        /** @var string $siteHost */
        $siteHost = static::getContainer()->getParameter('site_host');
        $this->site = 'https://'.$siteHost;
        $this->client->setServerParameter('HTTP_ORIGIN', $this->site);
        $this->suffix = bin2hex(random_bytes(3));
    }

    public function testGuestIsRedirectedAndHistoryShowsOnlyOwnOrders(): void
    {
        $this->client->request('GET', $this->site.'/account/orders');
        self::assertResponseRedirects($this->site.'/account/login');

        $me = $this->customer('me');
        $other = $this->customer('other');
        $product = $this->product('H1');
        $mine = $this->order('+380671000001', 'me-'.$this->suffix.'@example.test', $product, $me);
        $theirs = $this->order('+380671000002', 'other-'.$this->suffix.'@example.test', $product, $other);
        $this->client->loginUser($me, 'site');

        $crawler = $this->client->request('GET', $this->site.'/account/orders');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $this->client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertCount(1, $crawler->filter('table.orders tbody tr'));
        self::assertStringContainsString($mine->getNumber(), $crawler->filter('table.orders')->text());
        self::assertStringNotContainsString($theirs->getNumber(), $crawler->filter('table.orders')->text());
        self::assertCount(0, $crawler->filter('form.orders-claim'), 'нічиїх замовлень на цю пошту нема — кнопки нема');

        // головна кабінету: останнє власне замовлення над посиланнями (UX-аудит S15)
        $crawler = $this->client->request('GET', $this->site.'/account/');
        self::assertResponseIsSuccessful();
        self::assertSame($mine->getNumber(), $crawler->filter('.account-last-order a')->text());
        self::assertCount(1, $crawler->filter('.account-last-order .order-status-'.$mine->getStatus()));

        $crawler = $this->client->request('GET', $this->site.'/account/orders/'.$mine->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($mine->getNumber(), $crawler->filter('h1')->text());
        self::assertStringContainsString('Нове', $crawler->filter('.order-status')->text());
        self::assertStringContainsString('H1-'.$this->suffix, $crawler->filter('table.order-items')->text());

        // IDOR: чуже замовлення — 404 і на перегляд, і на повтор; неіснуюче — 404
        $this->client->request('GET', $this->site.'/account/orders/'.$theirs->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $this->site.'/account/orders/'.$theirs->getId().'/reorder', ['_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->site.'/account/orders/999999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testClaimLinksOnlyUnownedOrdersWithExactVerifiedEmail(): void
    {
        $me = $this->customer('claim');
        $email = 'claim-'.$this->suffix.'@example.test';
        $other = $this->customer('rival');
        $product = $this->product('C1');
        $guest1 = $this->order('+380672000001', $email, $product);
        $guest2 = $this->order('+380672000002', strtoupper($email), $product); // Order нормалізує email у нижній регістр
        $foreign = $this->order('+380672000003', 'someone-'.$this->suffix.'@example.test', $product);
        $owned = $this->order('+380672000004', $email, $product, $other); // той самий email, але вже чуже: лишається
        $this->client->loginUser($me, 'site');

        $crawler = $this->client->request('GET', $this->site.'/account/orders');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('table.orders'), 'до прив\'язки історія порожня');
        $form = $crawler->filter('form.orders-claim');
        self::assertCount(1, $form);
        self::assertStringContainsString('2', $form->text(), 'лічильник: лише нічиї з цією адресою');

        // чужий Origin — stateless CSRF відкидає, нічого не прив'язано
        $this->client->request('POST', $this->site.'/account/orders/claim', ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(2, static::getContainer()->get(OrderClaims::class)->available($me));

        $this->client->submit($form->form());
        self::assertResponseRedirects($this->site.'/account/orders');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Додано замовлень до історії: 2', $crawler->filter('.flash-success')->text());
        self::assertCount(2, $crawler->filter('table.orders tbody tr'));
        self::assertCount(0, $crawler->filter('form.orders-claim'), 'після прив\'язки кнопки нема');

        $this->em()->clear();
        foreach ([$guest1, $guest2] as $order) {
            $fresh = $this->em()->find(Order::class, $order->getId()) ?? self::fail();
            self::assertSame($me->getId(), $fresh->getCustomer()?->getId());
            self::assertSame(OrderClaims::METHOD_EMAIL, $fresh->getClaimMethod());
            self::assertNotNull($fresh->getClaimedAt());
            self::assertNull($fresh->getCustomerGroupCode(), 'група не дописується: ціни вже пораховані');
        }
        self::assertNull($this->em()->find(Order::class, $foreign->getId())?->getCustomer(), 'інша адреса — не чіпаємо');
        self::assertSame($other->getId(), $this->em()->find(Order::class, $owned->getId())?->getCustomer()?->getId(), 'чуже лишається чужим');

        // повторне натискання — нічого нового
        $this->client->request('POST', $this->site.'/account/orders/claim', ['_token' => 'csrf-token']);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('не знайдено', $crawler->filter('.flash-error')->text());
    }

    public function testUnverifiedCustomerCannotClaim(): void
    {
        $unverified = new Customer('unv-'.$this->suffix.'@example.test', 'Без підтвердження', 'uk');
        $this->em()->persist($unverified);
        $this->em()->flush();
        $guest = $this->order('+380673000001', $unverified->getEmail(), $this->product('U1'));
        $this->client->loginUser($unverified, 'site');

        $crawler = $this->client->request('GET', $this->site.'/account/orders');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form.orders-claim'), 'без підтвердження кнопки нема і кількість не показується');
        $this->client->request('POST', $this->site.'/account/orders/claim', ['_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertNull($this->em()->find(Order::class, $guest->getId())?->getCustomer());
    }

    public function testReorderAddsAvailableLinesAtCurrentPricesAndReportsUnavailable(): void
    {
        $me = $this->customer('re');
        $live = $this->product('R1', 10_000);
        $gone = $this->product('R2', 5_000);
        $order = $this->order('+380674000001', $me->getEmail(), $live, $me, [[$live, '2'], [$gone, '1']]);
        // після замовлення: ціна першого змінилась, другий знято з продажу
        $live->getDefaultVariant()->setPrice(Money::of(12_000, 'UAH'));
        $gone->setActive(false);
        $this->em()->flush();
        $this->client->loginUser($me, 'site');

        $crawler = $this->client->request('GET', $this->site.'/account/orders/'.$order->getId());
        $this->client->submit($crawler->filter('form.order-reorder')->form());
        self::assertResponseRedirects($this->site.'/cart/');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Додано позицій у кошик: 1', $crawler->filter('.flash-success')->text());
        self::assertStringContainsString('R2-'.$this->suffix, $crawler->filter('.flash-error')->text(), 'недоступний товар названо');
        self::assertStringContainsString("240,00\u{a0}₴", $crawler->filter('.cart-total')->text(), '2 × поточна ціна 120.00, не 100.00 з замовлення');

        $cookie = $this->client->getCookieJar()->get(CartResolver::COOKIE);
        self::assertNotNull($cookie);
        $this->em()->clear();
        $cart = $this->em()->getRepository(Cart::class)->findOneBy(['tokenHash' => Cart::hashToken($cookie->getValue())]);
        self::assertInstanceOf(Cart::class, $cart);
        self::assertCount(1, $cart->getItems());
        self::assertSame('R1-'.$this->suffix, $cart->getItems()->first()->getVariant()->getSku());
        self::assertSame('2.000', $cart->getItems()->first()->getQuantity());

        // замовлення лише з недоступним товаром: у кошик нічого, назад на сторінку замовлення з повідомленням
        $me = $this->em()->find(Customer::class, $me->getId()) ?? self::fail();
        $gone = $this->em()->find(Product::class, $gone->getId()) ?? self::fail();
        $only = $this->order('+380674000002', $me->getEmail(), $gone, $me);
        $this->client->request('POST', $this->site.'/account/orders/'.$only->getId().'/reorder', ['_token' => 'csrf-token']);
        self::assertResponseRedirects($this->site.'/account/orders/'.$only->getId());
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('.flash-success'));
        self::assertStringContainsString('R2-'.$this->suffix, $crawler->filter('.flash-error')->text());
    }

    public function testNewOrderOfLoggedInCustomerAppearsInHistory(): void
    {
        $me = $this->customer('new');
        $this->product('N1');
        $this->client->loginUser($me, 'site');
        $crawler = $this->client->request('GET', $this->site.'/n1-'.$this->suffix);
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form.quick-order')->form(['name' => 'Іван', 'phone' => '+380675000001', 'quantity' => '1']));
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', $this->site.'/account/orders');
        self::assertCount(1, $crawler->filter('table.orders tbody tr'));
        $this->em()->clear();
        $order = $this->em()->getRepository(Order::class)->findOneBy(['phone' => '+380675000001']);
        self::assertSame($me->getId(), $order?->getCustomer()?->getId());
        self::assertNull($order?->getClaimedAt(), 'оформлене залогіненим — не claim');
    }

    private function customer(string $tag): Customer
    {
        $customer = new Customer($tag.'-'.$this->suffix.'@example.test', 'Покупець', 'uk');
        $customer->verifyEmail(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, 'correct horse battery'));
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    private function product(string $code, int $priceMinor = 10_000): Product
    {
        $product = new Product($code.'-'.$this->suffix, 'UAH');
        $product->translate('uk')->setName('Товар '.$code)->setSlug(strtolower($code).'-'.$this->suffix);
        $product->getDefaultVariant()->setPrice(Money::of($priceMinor, 'UAH'));
        $product->getDefaultVariant()->setStock('100');
        $this->em()->persist($product);
        $this->em()->flush();

        return $product;
    }

    /** @param list<array{Product, string}>|null $lines */
    private function order(string $phone, string $email, Product $product, ?Customer $customer = null, ?array $lines = null): Order
    {
        $order = new Order('T'.substr(bin2hex(random_bytes(6)), 0, 11), OrderSource::Checkout, 'uk', 'Покупець', $phone, 'UAH', ShippingMethod::ManagerContact, PaymentMethod::ManagerContact);
        $order->setEmail($email);
        $order->setCustomer($customer, null);
        foreach ($lines ?? [[$product, '1']] as [$p, $quantity]) {
            $variant = $p->getDefaultVariant();
            $order->addItem(new OrderItem($order, $variant, $variant->getSku(), $variant->getPrice(), $quantity));
        }
        $order->recalculateTotals();
        $this->em()->persist($order);
        $this->em()->flush();

        return $order;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
