<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Order\Entity\Order;
use App\Core\Shared\Money\Money;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * E13 PR-7, мінімум замовлення групи (консиліум 07.10 п. 3.5): кошик і checkout показують, скільки бракує, PlaceOrder
 * відмовляє і для checkout, і для швидкого замовлення з картки (інакше мінімум обходився б швидким замовленням);
 * гість і покупець без групи мінімуму не мають.
 * Група «Опт»: сума від 50.00 UAH і 4 одиниці; V1 коштує 10.00.
 */
final class OrderMinimumTest extends CatalogPageTestCase
{
    private const string PASSWORD = 'correct horse battery';
    private const string PHONE = '+380671234599';
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $group = new CustomerGroup('wholesale_'.$this->suffix, 'Опт');
        $group->setMinOrderAmount(Money::of(5000, 'UAH'));
        $group->setMinOrderQuantity('4');
        $this->customer = new Customer('min-'.$this->suffix.'@example.test', 'Оптовик', self::defaultLocale());
        $this->customer->verifyEmail(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->customer, self::PASSWORD));
        $this->customer->setCustomerGroup($group);
        $this->em()->persist($group);
        $this->em()->persist($this->customer);
        $this->em()->flush();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->client->setServerParameter('REMOTE_ADDR', '203.0.113.'.random_int(1, 254));
    }

    public function testCartCheckoutAndQuickOrderEnforceGroupMinimum(): void
    {
        $this->login();
        $this->add('V1', '2'); // 20.00, 2 одиниці: бракує 30.00 і 2
        $crawler = $this->client->request('GET', 'http://localhost/cart/');
        self::assertResponseIsSuccessful();
        $minimum = $crawler->filter('.order-minimum');
        self::assertCount(1, $minimum, 'кошик показує мінімум групи');
        self::assertStringContainsString('30,00', $minimum->text());
        self::assertStringContainsString('бракує 2', $minimum->text());

        $crawler = $this->client->request('GET', 'http://localhost/checkout/');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.order-minimum'), 'checkout показує мінімум групи');
        $this->client->submit($crawler->filter('form.checkout')->form([
            'name' => 'Оптовик', 'phone' => self::PHONE, 'shipping' => 'nova_poshta', 'city' => 'Київ', 'address' => 'Відділення 1', 'payment' => 'cod',
        ]));
        self::assertResponseStatusCodeSame(422, 'PlaceOrder відмовляє нижче мінімуму');
        self::assertCount(1, $this->client->getCrawler()->filter('.order-minimum'), 'checkout показує локалізований блок мінімуму');
        self::assertStringNotContainsString('Мінімальне замовлення', $this->client->getCrawler()->filter('.flash-error')->text(), 'текст PlaceOrder не дублює блок');
        self::assertNull($this->em()->getRepository(Order::class)->findOneBy(['phone' => self::PHONE]), 'замовлення не створено');

        // швидке замовлення з картки тим самим мінімумом не обійти
        $this->quickOrder('1');
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Мінімальне замовлення', $crawler->filter('.flash-error')->text());
        self::assertNull($this->em()->getRepository(Order::class)->findOneBy(['phone' => self::PHONE]));

        // 5 × 10.00 = 50.00 і 5 одиниць (залишок V1 — 5) — проходить
        $this->quickOrder('5');
        self::assertResponseRedirects();
        self::assertMatchesRegularExpression('#^/order/[a-f0-9]+/thanks$#', (string) $this->client->getResponse()->headers->get('Location'));
        $this->em()->clear();
        $order = $this->em()->getRepository(Order::class)->findOneBy(['phone' => self::PHONE]);
        self::assertNotNull($order);
        self::assertSame(5000, $order->getTotal()->amount);
    }

    public function testGuestHasNoMinimum(): void
    {
        $this->quickOrder('1');
        self::assertResponseRedirects();
        self::assertMatchesRegularExpression('#^/order/[a-f0-9]+/thanks$#', (string) $this->client->getResponse()->headers->get('Location'), 'гість без групи замовляє 1 шт');
    }

    private function quickOrder(string $quantity): void
    {
        $crawler = $this->get($this->productUrl($this->fx->p['P1'], self::defaultLocale()));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form.quick-order')->form(['variant' => (string) $this->fx->v['V1']->getId(), 'name' => 'Покупець', 'phone' => self::PHONE, 'quantity' => $quantity]));
    }

    private function add(string $variant, string $quantity): void
    {
        $this->client->request('POST', 'http://localhost/cart/add', ['variant' => (string) $this->fx->v[$variant]->getId(), 'quantity' => $quantity, '_token' => 'csrf-token']);
        self::assertResponseRedirects('/cart/');
    }

    private function login(): void
    {
        $crawler = $this->client->request('GET', 'http://localhost/account/login');
        $this->client->submit($crawler->filter('form.account-form')->form(['email' => $this->customer->getEmail(), 'password' => self::PASSWORD]));
        self::assertResponseRedirects('/account/');
    }
}
