<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Entity\Product;
use App\Core\Order\Entity\Order;
use App\Core\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Checkout з довідником НП (Ф3): JSON для автодоповнення, снепшот обраного відділення в замовленні, серверний
 * фолбек-select без JS, текстовий фолбек як раніше; без даних довідника — звичайні текстові поля.
 */
final class NovaPoshtaCheckoutTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;
    private string $host;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->host = 'https://'.$host;
        $product = new Product('NP-1', 'UAH');
        $product->translate('uk')->setName('Двері НП')->setSlug('dveri-np');
        $product->getDefaultVariant()->setPrice(Money::of(100_000, 'UAH'));
        $product->getDefaultVariant()->setStock('5');
        $this->em->persist($product);
        $this->em->flush();
    }

    public function testWithoutDirectoryDataCheckoutKeepsPlainTextFields(): void
    {
        $crawler = $this->addToCartAndOpenCheckout();
        self::assertCount(0, $crawler->filter('.np-picker'), 'Довідник порожній — звичайні поля');
        self::assertCount(1, $crawler->filter('input[name="city"]'));
        self::assertCount(0, $crawler->filter('script[src*="site-checkout-np"]'));
        $this->client->request('GET', $this->host.'/np/cities.json?q=Ки');
        self::assertResponseStatusCodeSame(404);
    }

    public function testJsonEndpointsAndOrderSnapshot(): void
    {
        $this->seedDirectory();
        $crawler = $this->addToCartAndOpenCheckout();
        self::assertCount(1, $crawler->filter('.np-picker[data-np-cities][data-np-warehouses]'));
        self::assertCount(1, $crawler->filter('script[src*="site-checkout-np.js"]'));
        self::assertCount(1, $crawler->filter('input[name="city"][list="np-cities"]'));
        self::assertCount(1, $crawler->filter('select[name="np_warehouse_ref"][disabled]'), 'До вибору міста select порожній і вимкнений');

        $this->client->request('GET', $this->host.'/np/cities.json?q=Ки');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('max-age=300', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([['ref' => 'c-kyiv', 'name' => 'Київ', 'label' => 'Київ, Київська обл.']], $json['items']);
        $this->client->request('GET', $this->host.'/ru/np/cities.json?q=Кие');
        self::assertSame('Киев', json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['items'][0]['name'], 'Мова маршруту → назва ru');
        $this->client->request('GET', $this->host.'/np/warehouses.json?city=c-kyiv');
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['1', '2'], array_column($json['items'], 'number'));
        self::assertSame('branch', $json['items'][0]['type']);

        // оформлення з обраним відділенням (як зробив би JS): снепшот у замовленні, текст міста/адреси — з довідника
        // select наповнює JS, тож DomCrawler не знає значення — шлемо POST як браузер після вибору відділення
        $this->client->request('POST', $this->host.'/checkout/', [
            '_token' => $crawler->filter('form.checkout input[name="_token"]')->attr('value'),
            'key' => $crawler->filter('form.checkout input[name="key"]')->attr('value'),
            'price' => $crawler->filter('form.checkout input[name="price"]')->attr('value'),
            'name' => 'Ірина', 'phone' => '0501112233', 'shipping' => 'nova_poshta', 'city' => 'щось своє', 'np_city_ref' => 'c-kyiv', 'np_warehouse_ref' => 'w-2', 'payment' => 'cod',
        ]);
        self::assertResponseRedirects();
        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->findOneBy(['phone' => '+380501112233']);
        self::assertNotNull($order);
        self::assertSame('Київ', $order->getShippingCity());
        self::assertSame('Відділення №2: вул. Богатирська, 11', $order->getShippingAddress());
        $point = $order->getShippingPoint();
        self::assertNotNull($point);
        self::assertSame('w-2', $point->warehouseRef);
        self::assertSame('c-kyiv', $point->cityRef);
        self::assertSame('2', $point->warehouseNumber);
        self::assertStringContainsString('w-2', (string) $order->getShippingPointSummary());
    }

    public function testNoJsFallbackOffersWarehouseSelectForRecognisedCity(): void
    {
        $this->seedDirectory();
        $crawler = $this->addToCartAndOpenCheckout();
        // місто впізнано за назвою, відділення/адресу не вказано → 422 і select відділень цього міста
        $crawler = $this->client->submit($crawler->filter('form.checkout')->form(['name' => 'Ірина', 'phone' => '0501112233', 'shipping' => 'nova_poshta', 'city' => 'київ']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em->getRepository(Order::class)->count([]));
        self::assertStringContainsString('Оберіть відділення', $crawler->filter('.form-error')->text());
        $options = $crawler->filter('select[name="np_warehouse_ref"]:not([disabled]) option')->each(static fn ($o): string => (string) $o->attr('value'));
        self::assertSame(['', 'w-1', 'w-2'], $options);
        self::assertSame('c-kyiv', $crawler->filter('input[name="np_city_ref"]')->attr('value'));
        self::assertSame('Київ', $crawler->filter('input[name="city"]')->attr('value'), 'Назва нормалізована з довідника');

        // обирає відділення у фолбек-select → замовлення зі снепшотом
        $this->client->submit($crawler->filter('form.checkout')->form(['np_warehouse_ref' => 'w-1']));
        self::assertResponseRedirects();
        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->findOneBy(['phone' => '+380501112233']);
        self::assertSame('w-1', $order?->getShippingPoint()?->warehouseRef);
    }

    public function testFreeTextAddressStillWorks(): void
    {
        $this->seedDirectory();
        $crawler = $this->addToCartAndOpenCheckout();
        $this->client->submit($crawler->filter('form.checkout')->form(['name' => 'Ірина', 'phone' => '0501112233', 'shipping' => 'nova_poshta', 'city' => 'Невідоме село', 'address' => 'відділення біля ринку']));
        self::assertResponseRedirects();
        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->findOneBy(['phone' => '+380501112233']);
        self::assertNotNull($order);
        self::assertSame('Невідоме село', $order->getShippingCity());
        self::assertSame('відділення біля ринку', $order->getShippingAddress());
        self::assertNull($order->getShippingPoint(), 'Текстовий фолбек — без снепшота довідника');
    }

    private function addToCartAndOpenCheckout(): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', $this->host.'/dveri-np');
        $this->client->submit($crawler->filter('form.add-to-cart')->form(['quantity' => '1']));
        $crawler = $this->client->request('GET', $this->host.'/checkout/');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function seedDirectory(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->executeStatement('INSERT INTO shipping_np_city (ref, name, name_ru, area, settlement_type, updated_at) VALUES (?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?)', ['c-kyiv', 'Київ', 'Киев', 'Київська', 'місто', $now, 'c-lviv', 'Львів', 'Львов', 'Львівська', 'місто', $now]);
        $this->db->executeStatement('INSERT INTO shipping_np_warehouse (ref, city_ref, number, name, name_ru, short_address, type, active, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?), (?, ?, ?, ?, ?, ?, ?, 1, ?), (?, ?, ?, ?, ?, ?, ?, 0, ?)', [
            'w-1', 'c-kyiv', '1', 'Відділення №1: вул. Пирогівський шлях, 135', 'Отделение №1', 'Київ, Пирогівський шлях 135', 'branch', $now,
            'w-2', 'c-kyiv', '2', 'Відділення №2: вул. Богатирська, 11', 'Отделение №2', 'Київ, Богатирська 11', 'branch', $now,
            'w-3', 'c-kyiv', '3', 'Відділення №3 (не працює)', 'Отделение №3', 'Київ', 'branch', $now,
        ]);
    }
}
