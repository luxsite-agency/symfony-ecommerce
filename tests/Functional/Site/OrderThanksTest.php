<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Entity\Product;
use App\Core\Order\Entity\Order;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Сторінка «дякуємо» (консиліум безпеки 10.10.2026, п. 4): токен з адреси зникає після першого показу (302 на /order/thanks),
 * саме ця адреса йде в аналітику; прострочений токен (app.order_thanks_ttl) — «прийнято» без ПД і без тегів; purchase —
 * один раз на замовлення; перший скрипт сторінки прибирає токен з history до тегів аналітики.
 */
final class OrderThanksTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $host;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->host = 'https://'.$host;
        $this->client->setServerParameter('HTTP_ORIGIN', $this->host);
        $product = new Product('THX-1', 'UAH');
        $product->translate('uk')->setName('Двері дякуємо')->setSlug('dveri-thanks');
        $product->getDefaultVariant()->setPrice(Money::of(100_000, 'UAH'));
        $product->getDefaultVariant()->setStock('10');
        $this->em->persist($product);
        $this->em->flush();
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

    public function testTokenLeavesAddressBeforeAnalyticsAndPurchaseIsPushedOnce(): void
    {
        $tokenUrl = $this->placeQuickOrder('0671234567');
        $order = $this->order('+380671234567');

        // швидке замовлення — без сесії до цього моменту: посилання з токеном кладе замовлення в сесію і 302 без токена
        $this->client->request('GET', $tokenUrl);
        self::assertResponseRedirects($this->host.'/order/thanks');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($order->getSuccessToken(), $this->client->getRequest()->getUri(), 'адреса для аналітики — без токена');
        self::assertStringContainsString($order->getNumber(), $crawler->filter('h1')->text());
        self::assertStringContainsString('+380671234567', $crawler->filter('.thanks-intro')->text());
        self::assertSame(['purchase'], array_column(self::events($crawler), 'event'));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('history.replaceState', self::firstExecutableScript($crawler), 'перший виконуваний скрипт — до тегів аналітики');
        self::assertStringContainsString('googletagmanager', $html, 'теги на сторінці без токена є');
        self::assertLessThan(strpos($html, 'googletagmanager'), strpos($html, 'history.replaceState'));

        // повторні відкриття: і сторінки, і посилання з токеном — без другого purchase
        $crawler = $this->client->request('GET', $this->host.'/order/thanks');
        self::assertResponseIsSuccessful();
        self::assertSame([], array_column(self::events($crawler), 'event'));
        $this->client->request('GET', $tokenUrl);
        self::assertResponseRedirects($this->host.'/order/thanks');
        $crawler = $this->client->followRedirect();
        self::assertSame([], array_column(self::events($crawler), 'event'));

        // нова сесія без замовлення — на головну, а не чужі дані
        $this->client->restart();
        $this->client->request('GET', $this->host.'/order/thanks');
        self::assertResponseRedirects($this->host.'/');
    }

    public function testExpiredTokenShowsNoPersonalDataAndNoTags(): void
    {
        $tokenUrl = $this->placeQuickOrder('0671234568');
        $order = $this->order('+380671234568');
        $this->em->getConnection()->executeStatement('UPDATE sales_order SET placed_at = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE id = ?', [$order->getId()]);

        $crawler = $this->client->request('GET', $tokenUrl);
        self::assertResponseStatusCodeSame(410);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Замовлення прийнято', $crawler->filter('h1')->text());
        foreach ([$order->getNumber(), '+380671234568', 'Двері дякуємо', 'THX-1'] as $pd) {
            self::assertStringNotContainsString($pd, $html, 'без ПД: '.$pd);
        }
        self::assertStringNotContainsString('googletagmanager', $html, 'токен у адресі — жодних тегів аналітики');
        self::assertCount(0, $crawler->filter('script[data-analytics-events]'));
        self::assertStringContainsString('history.replaceState', self::firstExecutableScript($crawler));
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        // і сторінка без токена після строку нічого не показує, навіть із сесією
        $this->client->request('GET', $this->host.'/order/thanks');
        self::assertResponseRedirects($this->host.'/');
    }

    private function placeQuickOrder(string $phone): string
    {
        $crawler = $this->client->request('GET', $this->host.'/dveri-thanks');
        $this->client->submit($crawler->filter('form.quick-order')->form(['name' => 'Іван', 'phone' => $phone, 'quantity' => '1']));
        self::assertResponseRedirects();
        $url = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/order/[a-f0-9]{48}/thanks$#', $url);

        return $url;
    }

    private function order(string $phone): Order
    {
        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->findOneBy(['phone' => $phone]);
        self::assertInstanceOf(Order::class, $order);

        return $order;
    }

    /** Перший скрипт, який браузер виконує (JSON-LD не рахується). */
    private static function firstExecutableScript(Crawler $crawler): string
    {
        return (string) $crawler->filter('script:not([type="application/ld+json"]):not([type="application/json"])')->first()->text();
    }

    /** @return list<array<string, mixed>> */
    private static function events(Crawler $crawler): array
    {
        $script = $crawler->filter('script[data-analytics-events]');
        if (0 === $script->count()) {
            return [];
        }
        $events = json_decode($script->text(), true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($events) ? array_values($events) : [];
    }
}
