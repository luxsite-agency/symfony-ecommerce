<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Enum\SalesUnit;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Mail\OrderMailer;
use App\Core\Order\Message\SendCustomerOrderEmail;
use App\Core\Order\Message\SendShopOrderEmail;
use App\Core\Order\MessageHandler\SendCustomerOrderEmailHandler;
use App\Core\Order\MessageHandler\SendShopOrderEmailHandler;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Core\Security\Enum\UserRole;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use App\Tests\Functional\Admin\AdminWebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;

/**
 * Снапшоти формату грошей і кількостей на всьому шляху замовлення (UX-аудит 2026-10-18, PR-1): кошик, оформлення,
 * лист покупцю (text + html), лист магазину, картка замовлення в адмінці. Один набір: 32 шт × 49 ₴ + 2,5 м × 8 ₴ = 1 588,00 ₴.
 * Раніше тут було «1568.00 UAH», «32.000», «2.500» поруч із «1 588,00 ₴» у каталозі.
 */
final class FormatSnapshotTest extends AdminWebTestCase
{
    private Product $piece;
    private Product $meter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->piece = new Product('FMT-PC', 'UAH');
        $this->piece->translate('uk')->setName('Шуруп')->setSlug('fmt-shurup');
        $this->piece->getDefaultVariant()->setPrice(Money::of(4_900, 'UAH'));
        $this->piece->getDefaultVariant()->setStock('100');
        $this->meter = new Product('FMT-M', 'UAH');
        $this->meter->translate('uk')->setName('Профіль')->setSlug('fmt-profil');
        $this->meter->getDefaultVariant()->setPrice(Money::of(800, 'UAH'));
        $this->meter->getDefaultVariant()->setStock('10');
        $this->meter->getDefaultVariant()->setUnit(SalesUnit::Meter);
        $this->meter->getDefaultVariant()->setQuantityRules('0.5', '0.5');
        $this->em()->persist($this->piece);
        $this->em()->persist($this->meter);
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(Settings::class)->invalidate();
        parent::tearDown();
    }

    public function testCartAndCheckout(): void
    {
        $site = 'https://'.static::getContainer()->getParameter('site_host');
        foreach (['fmt-shurup' => '32', 'fmt-profil' => '2.5'] as $slug => $quantity) {
            $crawler = $this->client->request('GET', $site.'/'.$slug);
            $this->client->submit($crawler->filter('form.add-to-cart')->form(['quantity' => $quantity]));
        }

        $crawler = $this->client->request('GET', $site.'/cart/');
        self::assertResponseIsSuccessful();
        self::assertSame([
            'FMT-PC | 49,00 ₴ | 32 | 1 568,00 ₴',
            'FMT-M | 8,00 ₴ | 2.5 | 20,00 ₴',
        ], $crawler->filter('tr.cart-line')->each(static fn (Crawler $row): string => implode(' | ', [
            $row->attr('data-sku'),
            self::text($row->filter('td')->eq(1)),
            $row->filter('input[name="quantity"]')->attr('value'), // значення поля — машинне, без «.000»
            self::text($row->filter('td')->eq(3)),
        ])));
        self::assertSame('1 588,00 ₴', self::text($crawler->filter('.cart-total')));

        $crawler = $this->client->request('GET', $site.'/checkout/');
        self::assertResponseIsSuccessful();
        self::assertSame(['Шуруп × 32 шт 1 568,00 ₴', 'Профіль × 2,5 м 20,00 ₴'], $crawler->filter('.checkout-summary li, aside li')->each(static fn (Crawler $li): string => self::text($li)));
        self::assertSame('Разом: 1 588,00 ₴', self::text($crawler->filter('.checkout-total')));
    }

    public function testEmailsAndAdminOrder(): void
    {
        static::getContainer()->get(Settings::class)->set(SettingKeys::ORDERS_NOTIFY_EMAIL, 'sales@example.test');
        $order = static::getContainer()->get(PlaceOrder::class)->place(new PlaceOrderRequest(OrderSource::Quick, 'uk', 'Тест', '0501112233', [
            ['variant' => $this->piece->getDefaultVariant(), 'quantity' => '32'],
            ['variant' => $this->meter->getDefaultVariant(), 'quantity' => '2.5'],
        ], email: 'buyer@example.test'));
        $mailer = static::getContainer()->get(OrderMailer::class);
        (new SendCustomerOrderEmailHandler($this->em(), $mailer))(new SendCustomerOrderEmail((int) $order->getId()));
        (new SendShopOrderEmailHandler($this->em(), $mailer))(new SendShopOrderEmail((int) $order->getId()));

        $customer = self::getMailerMessage(0);
        $shop = self::getMailerMessage(1);
        self::assertInstanceOf(Email::class, $customer);
        self::assertInstanceOf(Email::class, $shop);
        self::assertStringContainsString(implode("\n", [
            '- Шуруп (FMT-PC) × 32 шт = 1 568,00 ₴',
            '- Профіль (FMT-M) × 2,5 м = 20,00 ₴',
            'Разом: 1 588,00 ₴',
        ]), self::plain((string) $customer->getTextBody()));
        self::assertSame([
            'Шуруп FMT-PC | 32 шт | 49,00 ₴ | 1 568,00 ₴',
            'Профіль FMT-M | 2,5 м | 8,00 ₴ | 20,00 ₴',
        ], (new Crawler(str_replace('<br>', ' ', (string) $customer->getHtmlBody())))->filter('tbody tr')->each(static fn (Crawler $row): string => implode(' | ', $row->filter('td')->each(static fn (Crawler $td): string => self::text($td)))));
        self::assertStringContainsString(implode("\n", [
            '- FMT-PC Шуруп × 32 шт = 1 568,00 ₴',
            '- FMT-M Профіль × 2,5 м = 20,00 ₴',
            'Разом: 1 588,00 ₴',
        ]), self::plain((string) $shop->getTextBody()));
        self::assertStringEndsWith('— 1 588,00 ₴', self::plain((string) $shop->getSubject()));

        $this->loginAs(UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/order/%d', $order->getId())));
        self::assertResponseIsSuccessful();
        $items = $crawler->filter('h3 + table.datagrid')->first();
        self::assertSame([
            'FMT-PC | Шуруп | 49,00 ₴ | 32 шт | 1 568,00 ₴ | 32 шт',
            'FMT-M | Профіль | 8,00 ₴ | 2,5 м | 20,00 ₴ | 2,5 м',
        ], $items->filter('tbody tr')->each(static fn (Crawler $row): string => implode(' | ', $row->filter('td')->each(static fn (Crawler $td): string => self::text($td)))));
        self::assertSame(['Товари 1 588,00 ₴', 'Доставка 0,00 ₴', 'Разом 1 588,00 ₴'], $items->filter('tfoot tr')->each(static fn (Crawler $row): string => self::plain(implode(' ', $row->filter('th')->each(static fn (Crawler $th): string => self::text($th))))));

        $crawler = $this->client->request('GET', $this->adminUrl('/order'));
        self::assertStringContainsString('1 588,00 ₴', self::text($crawler->filter('table tbody')));
        self::assertMatchesRegularExpression('/\b\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}\b(?!:)/', self::text($crawler->filter('table tbody')), 'дата без секунд');
    }

    private static function text(Crawler $node): string
    {
        return self::plain($node->text());
    }

    /** NBSP → пробіл лише для читабельності очікувань; що NBSP справді стоїть, перевіряє DisplayFormatTest. */
    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/[ \t]+/u', ' ', str_replace("\u{a0}", ' ', $text)));
    }
}
