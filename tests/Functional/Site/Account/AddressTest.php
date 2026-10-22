<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site\Account;

use App\Core\Catalog\Entity\Product;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerAddress;
use App\Core\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** E12 PR-6, адреси: кілька адрес, одна основна, відділення НП з довідника, лише свої (IDOR), підстановка на checkout. */
final class AddressTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $base;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects(false);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->base = 'https://'.$host;
        $this->client->setServerParameter('HTTP_ORIGIN', $this->base);
        $this->client->setServerParameter('REMOTE_ADDR', '203.0.113.'.random_int(1, 254)); // ліміти листів з IP спільні на процес
    }

    public function testCrudDefaultAndOwnership(): void
    {
        $me = $this->customer();
        $other = $this->customer();
        $this->client->loginUser($me, 'site');
        $crawler = $this->client->request('GET', $this->base.'/account/addresses');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.addresses-empty'));

        // курʼєр: без адреси — помилка; з адресою — перша адреса стає основною сама
        $crawler = $this->client->request('GET', $this->base.'/account/addresses/new');
        $this->client->submit($crawler->filter('form.address-form')->form(['recipientName' => 'Олена', 'recipientPhone' => '0671234567', 'shipping' => 'courier', 'city' => 'Київ', 'address' => '']));
        self::assertResponseStatusCodeSame(422);
        $this->client->submit($this->client->getCrawler()->filter('form.address-form')->form(['label' => 'Дім', 'recipientName' => 'Олена', 'recipientPhone' => '0671234567', 'shipping' => 'courier', 'city' => 'Київ', 'address' => 'вул. Хрещатик, 1']));
        self::assertResponseRedirects($this->base.'/account/addresses');
        $first = $this->addresses($me)[0];
        self::assertTrue($first->isDefault());
        self::assertSame('+380671234567', $first->getRecipientPhone(), 'E.164');

        $crawler = $this->client->request('GET', $this->base.'/account/addresses/new');
        $form = $crawler->filter('form.address-form')->form(['label' => 'Офіс', 'recipientName' => 'Олена', 'recipientPhone' => '+380501112233', 'shipping' => 'courier', 'city' => 'Львів', 'address' => 'пл. Ринок, 1']);
        $default = $form['isDefault'];
        self::assertInstanceOf(ChoiceFormField::class, $default);
        $default->tick();
        $this->client->submit($form);
        self::assertResponseRedirects();
        [$a, $b] = $this->addresses($me);
        self::assertSame(['Офіс', true, 'Дім', false], [$a->getLabel(), $a->isDefault(), $b->getLabel(), $b->isDefault()], 'основна одна, нова стала основною і йде першою');

        // IDOR: чужа адреса — 404 на перегляд, зміну, видалення, «основна»
        $this->client->loginUser($other, 'site');
        foreach (['GET' => '', 'POST' => '/delete'] as $method => $suffix) {
            $this->client->request($method, $this->base.'/account/addresses/'.$a->getId().$suffix, ['_token' => 'csrf-token']);
            self::assertResponseStatusCodeSame(404);
        }
        $this->client->request('POST', $this->base.'/account/addresses/'.$a->getId().'/default', ['_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(404);
        self::assertCount(2, $this->addresses($me));

        // своя: зробити основною іншу, видалити основну → основною стає решта
        $this->client->loginUser($this->fresh($me), 'site');
        $this->client->request('POST', $this->base.'/account/addresses/'.$b->getId().'/default', ['_token' => 'csrf-token']);
        self::assertResponseRedirects();
        self::assertSame('Дім', $this->addresses($me)[0]->getLabel());
        $this->client->request('POST', $this->base.'/account/addresses/'.$b->getId().'/delete', ['_token' => 'csrf-token']);
        self::assertResponseRedirects();
        $left = $this->addresses($me);
        self::assertCount(1, $left);
        self::assertSame(['Офіс', true], [$left[0]->getLabel(), $left[0]->isDefault()]);
    }

    public function testNovaPoshtaWarehouseFromDirectoryAndCheckoutPrefill(): void
    {
        $this->seedDirectory();
        $me = $this->customer();
        $me->setPhone('0631112233');
        $this->em->flush();
        $product = new Product('ADR-1', 'UAH');
        $product->translate('uk')->setName('Двері адреси')->setSlug('dveri-adresy');
        $product->getDefaultVariant()->setPrice(Money::of(100_000, 'UAH'));
        $product->getDefaultVariant()->setStock('5');
        $this->em->persist($product);
        $this->em->flush();
        $this->client->loginUser($me, 'site');

        // без JS: місто впізнано, відділення не обрано → select відділень; потім відділення → снепшот
        $crawler = $this->client->request('GET', $this->base.'/account/addresses/new');
        self::assertCount(1, $crawler->filter('.np-picker'));
        $crawler = $this->client->submit($crawler->filter('form.address-form')->form(['recipientName' => 'Ірина', 'recipientPhone' => '0501112233', 'shipping' => 'nova_poshta', 'city' => 'київ']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('c-kyiv', $crawler->filter('input[name="npCityRef"]')->attr('value'));
        self::assertGreaterThan(1, $crawler->filter('select[name="npWarehouseRef"] option')->count());
        $this->client->submit($crawler->filter('form.address-form')->form(['npWarehouseRef' => 'w-2']));
        self::assertResponseRedirects($this->base.'/account/addresses');
        $address = $this->addresses($me)[0];
        self::assertSame(['w-2', 'c-kyiv', 'Київ'], [$address->getShippingPoint()?->warehouseRef, $address->getShippingPoint()?->cityRef, $address->getCity()]);
        self::assertStringContainsString('№2', (string) $address->getAddress());

        // checkout: контакти з профілю і адреса за замовчуванням уже підставлені, відділення обране без JS
        $this->client->request('POST', $this->base.'/cart/add', ['variant' => (string) $product->getDefaultVariant()->getId(), 'quantity' => '1', '_token' => 'csrf-token']);
        $crawler = $this->client->request('GET', $this->base.'/checkout/');
        self::assertResponseIsSuccessful();
        self::assertSame('Ірина', $crawler->filter('input[name="name"]')->attr('value'));
        self::assertSame('+380501112233', $crawler->filter('input[name="phone"]')->attr('value'));
        self::assertSame($me->getEmail(), $crawler->filter('input[name="email"]')->attr('value'));
        self::assertSame('c-kyiv', $crawler->filter('input[name="np_city_ref"]')->attr('value'));
        self::assertCount(1, $crawler->filter('select[name="np_warehouse_ref"] option[value="w-2"][selected]'));
        self::assertCount(1, $crawler->filter('.checkout-addresses a.current'));

        // гість нічого з цього не бачить
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', $this->base.'/cart/add', ['variant' => (string) $product->getDefaultVariant()->getId(), 'quantity' => '1', '_token' => 'csrf-token']);
        $crawler = $this->client->request('GET', $this->base.'/checkout/?address='.$address->getId());
        self::assertSame('', $crawler->filter('input[name="name"]')->attr('value'));
        self::assertCount(0, $crawler->filter('.checkout-addresses'));
    }

    private function customer(): Customer
    {
        $customer = new Customer('adr-'.bin2hex(random_bytes(4)).'@example.test', 'Покупець', 'uk');
        $customer->verifyEmail(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, 'correct horse battery'));
        $this->em->persist($customer);
        $this->em->flush();

        return $customer;
    }

    private function fresh(Customer $customer): Customer
    {
        $this->em->clear();

        return $this->em->find(Customer::class, $customer->getId()) ?? self::fail();
    }

    /** @return list<CustomerAddress> */
    private function addresses(Customer $customer): array
    {
        $this->em->clear();

        return $this->em->getRepository(CustomerAddress::class)->findBy(['customer' => $customer->getId()], ['isDefault' => 'DESC', 'id' => 'ASC']);
    }

    private function seedDirectory(): void
    {
        $db = static::getContainer()->get(Connection::class);
        $now = date('Y-m-d H:i:s');
        $db->executeStatement('INSERT IGNORE INTO shipping_np_city (ref, name, name_ru, area, settlement_type, updated_at) VALUES (?, ?, ?, ?, ?, ?)', ['c-kyiv', 'Київ', 'Киев', 'Київська', 'місто', $now]);
        $db->executeStatement('INSERT IGNORE INTO shipping_np_warehouse (ref, city_ref, number, name, name_ru, short_address, type, active, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?), (?, ?, ?, ?, ?, ?, ?, 1, ?)', [
            'w-1', 'c-kyiv', '1', 'Відділення №1: вул. Пирогівський шлях, 135', 'Отделение №1', 'Київ, Пирогівський шлях 135', 'branch', $now,
            'w-2', 'c-kyiv', '2', 'Відділення №2: вул. Богатирська, 11', 'Отделение №2', 'Київ, Богатирська 11', 'branch', $now,
        ]);
    }
}
