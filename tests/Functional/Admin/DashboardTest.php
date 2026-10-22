<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Enum\SalesUnit;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Core\Security\Enum\UserRole;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;

/** Дашборд адмінки: перша сторінка після входу, пункт меню «Дашборд», віджети за ролями, фільтр періоду. */
final class DashboardTest extends AdminWebTestCase
{
    public function testManagerSeesOrdersButNotIntegrations(): void
    {
        $product = new Product('DASH-1', 'UAH');
        $product->translate('uk')->setName('Двері дашборд');
        $product->getDefaultVariant()->setPrice(Money::of(60_000, 'UAH'));
        $product->getDefaultVariant()->setUnit(SalesUnit::Meter);
        $product->getDefaultVariant()->setQuantityRules('0.5', '0.5');
        $product->getDefaultVariant()->setStock('3');
        $this->em()->persist($product);
        $this->em()->flush();
        $order = static::getContainer()->get(PlaceOrder::class)->place(new PlaceOrderRequest(OrderSource::Quick, 'uk', 'Оксана', '0501112233', [['variant' => $product->getDefaultVariant(), 'quantity' => '2.5']]));

        $this->loginAs(UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl('/'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Дашборд', $crawler->filter('h1')->text());
        $menu = $crawler->filter('#main-menu, .sidebar, .main-menu')->text();
        self::assertStringContainsString('Дашборд', $menu, 'Пункт меню перейменовано');
        self::assertStringNotContainsString('Головна', $menu);

        self::assertSame('1', $crawler->filter('[data-kpi="today"]')->text());
        self::assertSame('1', $crawler->filter('[data-quick-count]')->text(), 'Швидка заявка в стані new');
        self::assertStringContainsString($order->getNumber(), $crawler->filter('#dashboard-recent')->text());
        self::assertStringContainsString(\sprintf('/order/%d', $order->getId()), (string) $crawler->filter('#dashboard-recent tbody a')->first()->attr('href'), 'Посилання на замовлення');
        self::assertStringContainsString('DASH-1', $crawler->filter('#dashboard-top')->text());
        self::assertStringContainsString('DASH-1', $crawler->filter('#dashboard-stock')->text(), 'Залишок 0,5 ≤ порога — у списку «мало»');
        self::assertSame('0,5 м', str_replace("\u{a0}", ' ', $crawler->filter('#dashboard-stock [data-qty]')->text()), 'залишок у метрах: 3 − 2,5');
        self::assertCount(30, $crawler->filter('#dashboard-sales [data-point]'), 'Типовий період — 30 днів, стовпчик на кожен день');
        self::assertCount(1, $crawler->filter('#dashboard-sales [data-percent="100"]'), 'Єдиний день із продажем (1500) — верхня позначка шкали');
        self::assertSame(['0 ₴', '500 ₴', '1 000 ₴', '1 500 ₴'], array_map(static fn (string $t): string => str_replace(["\u{a0}", "\u{202f}"], ' ', $t), $crawler->filter('#dashboard-sales [data-tick]')->each(static fn ($n): string => $n->text())), 'вісь сум: 0 і круглі кроки');
        $today = $crawler->filter('#dashboard-sales [data-point="'.(new DateTimeImmutable('today'))->format('Y-m-d').'"]');
        self::assertSame((new DateTimeImmutable('today'))->format('d.m').': 1 500,00 ₴, 1 замовлення', str_replace(["\u{a0}", "\u{202f}"], ' ', (string) $today->attr('title')), 'підказка: дата, сума, кількість замовлень');
        self::assertSame('0', $crawler->filter('#dashboard-sales [data-point]')->first()->attr('data-count'), 'дні без продажів теж мають стовпчик');
        self::assertGreaterThanOrEqual(4, $crawler->filter('#dashboard-sales [data-x-label]')->count(), 'дати під кількома стовпчиками');
        self::assertSame(['total', 'quantity'], $crawler->filter('#dashboard-top [data-top]')->each(static fn ($n): string => (string) $n->attr('data-top')), 'топ: дві вкладки, одна таблиця на кожну');
        self::assertSame('2,5 м', str_replace("\u{a0}", ' ', $crawler->filter('#dashboard-top [data-top="total"] [data-qty]')->text()), 'дробова кількість — з одиницею, без зайвих нулів');
        self::assertSame('DASH-1', $crawler->filter('#dashboard-top .dash-cut')->first()->attr('title'), 'артикул обрізається, повний — у title');
        self::assertCount(0, $crawler->filter('#dashboard-integrations'), 'Інтеграції — лише адміну');
        self::assertCount(0, $crawler->filter('#dashboard-analytics'));

        // фільтр періоду: пресети
        $crawler = $this->client->request('GET', $this->adminUrl('/?period=7'));
        self::assertResponseIsSuccessful();
        self::assertSame('7 днів', trim($crawler->filter('.period-filter a.active')->text()));
        self::assertCount(7, $crawler->filter('#dashboard-sales [data-point]'));
        self::assertCount(7, $crawler->filter('.period-filter a'), 'сьогодні, вчора, 7, 30, 90, цей і минулий місяць');
        $crawler = $this->client->request('GET', $this->adminUrl('/?period=bogus'));
        self::assertSame('30 днів', trim($crawler->filter('.period-filter a.active')->text()), 'Невідомий період → 30 днів');
        self::assertCount(1, $crawler->filter('[data-range-error="preset"]'));
        $crawler = $this->client->request('GET', $this->adminUrl('/?period=last_month'));
        $first = new DateTimeImmutable('first day of last month');
        $last = new DateTimeImmutable('last day of last month');
        self::assertSame('Минулий місяць', trim($crawler->filter('.period-filter a.active')->text()));
        self::assertSame($first->format('Y-m-d'), $crawler->filter('input[name="from"]')->attr('value'), 'поля заповнені поточним діапазоном');
        self::assertSame($last->format('Y-m-d'), $crawler->filter('input[name="to"]')->attr('value'));
        self::assertStringContainsString('з '.$first->format('d.m.Y').' по '.$last->format('d.m.Y'), $crawler->filter('[data-range-label]')->text(), 'заголовок віджета з датами');
        self::assertSame('0', $crawler->filter('[data-kpi="period-count"]')->text(), 'сьогоднішнє замовлення не в минулому місяці');

        // довільний діапазон через GET-форму: включно, посилання в список замовлень несе ті самі дати
        $from = (new DateTimeImmutable('-5 days'))->format('Y-m-d');
        $to = (new DateTimeImmutable('today'))->format('Y-m-d');
        $crawler = $this->client->submit($crawler->filter('form.dash-range')->form(['from' => $from, 'to' => $to]));
        self::assertResponseIsSuccessful();
        self::assertSame($from.'_'.$to, $crawler->filter('form.dash-range')->attr('data-range'));
        self::assertCount(0, $crawler->filter('.period-filter a.active'), 'довільний діапазон — жоден пресет не активний');
        self::assertCount(6, $crawler->filter('#dashboard-sales [data-point]'));
        self::assertSame('1', $crawler->filter('[data-kpi="period-count"]')->text());
        $listUrl = (string) $crawler->filter('[data-orders-in-range]')->attr('href');
        self::assertStringContainsString('filters%5BplacedAt%5D%5Bcomparison%5D=between', $listUrl);
        self::assertStringContainsString($from.'T00:00:00', $listUrl);
        self::assertStringContainsString($to.'T23:59:59', $listUrl);
        $crawler = $this->client->request('GET', $listUrl);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($order->getNumber(), $crawler->filter('table')->text(), 'список замовлень відфільтровано тим самим діапазоном');

        // некоректні дати — повідомлення і типові 30 днів
        $crawler = $this->client->request('GET', $this->adminUrl('/?from=2026-13-40&to='.$to));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('не розпізнано', $crawler->filter('[data-range-error="invalid"]')->text());
        self::assertSame('30 днів', trim($crawler->filter('.period-filter a.active')->text()));
        self::assertCount(30, $crawler->filter('#dashboard-sales [data-point]'));
        $crawler = $this->client->request('GET', $this->adminUrl('/?from='.$to.'&to='.$from));
        self::assertCount(1, $crawler->filter('[data-range-error="order"]'));
        // довгий діапазон — графік по тижнях
        $crawler = $this->client->request('GET', $this->adminUrl('/?from='.(new DateTimeImmutable('-200 days'))->format('Y-m-d').'&to='.$to));
        self::assertStringContainsString('по тижнях', $crawler->filter('#dashboard-sales h2')->text());
        self::assertLessThan(40, $crawler->filter('#dashboard-sales [data-point]')->count());
    }

    public function testAdminSeesIntegrationsAndAnalyticsWithSettingsLink(): void
    {
        $this->loginAs(UserRole::Admin, 'admin@example.test');
        $crawler = $this->client->request('GET', $this->adminUrl('/'));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#dashboard-integrations'));
        self::assertSame('0', $crawler->filter('#dashboard-integrations [data-failed-count]')->text());
        self::assertCount(1, $crawler->filter('#dashboard-analytics'));
        self::assertStringContainsString('/setting', (string) $crawler->filter('#dashboard-analytics a')->attr('href'), 'Посилання на Налаштування сайту');
        self::assertSame('0', $crawler->filter('#dashboard-analytics [data-tags-enabled]')->attr('data-tags-enabled'));
        self::assertStringContainsString('Жодного тегу не задано', $crawler->filter('#dashboard-analytics')->text());
        self::assertStringContainsString('Замовлень ще немає', $crawler->filter('#dashboard-recent')->text());
    }
}
