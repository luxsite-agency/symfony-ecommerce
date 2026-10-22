<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Admin\Dashboard\DashboardRange;
use App\Admin\Dashboard\DashboardStats;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Integration\Crm\CrmConnectorRegistry;
use App\Core\Order\Entity\Order;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\OrderTransitionService;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Агрегати дашборда: періоди, суми без скасованих, графік по днях, топ товарів, залишки, швидкі заявки, інтеграції, аналітика, кеш. */
final class DashboardStatsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;
    private DashboardStats $stats;
    private PlaceOrder $placeOrder;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->stats = self::getContainer()->get(DashboardStats::class);
        $this->placeOrder = self::getContainer()->get(PlaceOrder::class);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(Settings::class)->invalidate();
        parent::tearDown();
    }

    public function testAggregatesByPeriod(): void
    {
        $door = $this->product('DB-DOOR', 'Двері дашборд', 100_000, '10');
        $tile = $this->product('DB-TILE', 'Плитка дашборд', 2_500, '0');       // in_stock, залишок 0 → «немає»
        $handle = $this->product('DB-HANDLE', 'Ручка дашборд', 30_000, '3');   // ≤ 5 → «мало»
        $tile->getDefaultVariant()->setStockPolicy(StockPolicy::InStock);
        $custom = $this->product('DB-CUSTOM', 'На замовлення', 50_000, '0');
        $custom->getDefaultVariant()->setStockPolicy(StockPolicy::MadeToOrder); // без обліку — не тривога
        $this->em->flush();

        $today1 = $this->order([[$door, '2']], OrderSource::Checkout);                 // 2000.00
        $today2 = $this->order([[$handle, '1'], [$door, '1']], OrderSource::Quick);   // 1300.00, швидка заявка
        $cancelled = $this->order([[$door, '3']], OrderSource::Checkout);             // 3000.00, скасоване — не рахується
        self::getContainer()->get(OrderTransitionService::class)->apply($cancelled, 'cancel', 'test');
        $tenDaysAgo = $this->order([[$custom, '4']], OrderSource::Checkout);          // 2000.00
        $this->backdate($tenDaysAgo, '-10 days');
        $fortyDaysAgo = $this->order([[$door, '1']], OrderSource::Checkout);          // 1000.00, поза 30 днями
        $this->backdate($fortyDaysAgo, '-40 days');

        $s = $this->stats->snapshot(DashboardRange::preset('30'));
        self::assertSame(4, $s->orders->new, 'Стан new без прив’язки до періоду: усі, крім скасованого');
        self::assertSame(2, $s->orders->today);
        self::assertSame(2, $s->orders->last7Days);
        self::assertSame(3, $s->orders->last30Days);
        self::assertSame(3, $s->orders->periodCount);
        self::assertSame(530_000, $s->orders->periodTotal->amount, '2000 + 1300 + 2000, без скасованого');
        self::assertSame(176_666, $s->orders->averageCheck->amount);
        self::assertSame(['new' => 3, 'confirmed' => 0, 'processing' => 0, 'shipped' => 0, 'completed' => 0, 'cancelled' => 1], $s->orders->byStatus);

        self::assertCount(30, $s->salesByDay);
        $last = $s->salesByDay[29];
        self::assertSame((new DateTimeImmutable('today'))->format('Y-m-d'), $last->date);
        self::assertSame(2, $last->count);
        self::assertSame(330_000, $last->total->amount);
        self::assertSame([0, 200_000, 400_000], array_map(static fn ($m): int => $m->amount, $s->salesTicks), 'шкала: максимум 3300 → крок 2000, верх 4000');
        self::assertSame(83, $last->percent, 'висота — від верхньої позначки шкали: 3300 з 4000');
        self::assertSame(50, $s->salesByDay[19]->percent, '2000 з 4000');

        self::assertSame(['DB-DOOR', 'DB-CUSTOM', 'DB-HANDLE'], array_map(static fn ($t): string => $t->sku, $s->topByTotal));
        self::assertSame('3.000', $s->topByTotal[0]->quantity, 'Скасоване не враховано: 2 + 1');
        self::assertSame('pc', $s->topByTotal[0]->unit, 'одиниця з варіанта — для показу кількості');
        self::assertSame(300_000, $s->topByTotal[0]->total->amount);
        self::assertSame('DB-CUSTOM', $s->topByQuantity[0]->sku, '4 шт — найбільше за кількістю');

        self::assertSame(
            [$cancelled->getNumber(), $today2->getNumber(), $today1->getNumber(), $tenDaysAgo->getNumber(), $fortyDaysAgo->getNumber()],
            array_map(static fn ($o): string => $o->number, $s->recentOrders),
            'Останні — за датою створення (в межах секунди — за id), скасовані теж у списку',
        );
        self::assertSame(1, $s->quickRequestsCount);
        self::assertSame($today2->getNumber(), $s->quickRequests[0]->number);

        self::assertSame(1, $s->outOfStockCount);
        self::assertSame(1, $s->lowStockCount);
        self::assertSame(['DB-TILE', 'DB-HANDLE'], array_map(static fn ($a): string => $a->sku, $s->stockAlerts));
        self::assertSame('Плитка дашборд', $s->stockAlerts[0]->productName);
        self::assertTrue($s->stockAlerts[0]->isOut());

        // 7 днів — без замовлення 10-денної давності
        $week = $this->stats->snapshot(DashboardRange::preset('7'));
        self::assertSame(2, $week->orders->periodCount);
        self::assertSame(330_000, $week->orders->periodTotal->amount);
        self::assertCount(7, $week->salesByDay);
        // сьогодні
        $today = $this->stats->snapshot(DashboardRange::preset('today'));
        self::assertCount(1, $today->salesByDay);
        self::assertSame(2, $today->orders->periodCount);
    }

    public function testIntegrationsAndAnalytics(): void
    {
        $settings = self::getContainer()->get(Settings::class);
        $settings->set(SettingKeys::GTM_CONTAINER_ID, 'GTM-DASH1');
        $settings->set(SettingKeys::TAGS_ON_DEV, '1');
        $this->db->executeStatement("INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES ('x', '{}', 'failed', NOW(), NOW()), ('y', '{}', 'default', NOW(), NOW())");
        $this->db->executeStatement("INSERT INTO integration_external_ref (system_code, entity_type, entity_id, external_id, created_at) VALUES ('keycrm', 'product', 1, 'p-1', '2026-09-01 10:00:00')");

        $s = $this->stats->snapshot(DashboardRange::preset('30'));
        self::assertSame(self::getContainer()->get(CrmConnectorRegistry::class)->active()->getSystem(), $s->integrations->crmSystem);
        self::assertSame(1, $s->integrations->failedMessages);
        self::assertSame(1, $s->integrations->pendingMessages);
        self::assertNotNull($s->integrations->oldestPendingAt);
        self::assertNull($s->integrations->lastOrderPushAt);
        self::assertSame('2026-09-01 10:00', $s->integrations->lastCatalogSyncAt?->format('Y-m-d H:i'));
        self::assertTrue($s->analytics->gtm);
        self::assertFalse($s->analytics->ga4);
        self::assertFalse($s->analytics->pixel);
        self::assertTrue($s->analytics->enabled);
    }

    public function testSnapshotIsCachedUntilInvalidated(): void
    {
        $door = $this->product('DB-CACHE', 'Кеш', 100_000, '10');
        $this->em->flush();
        self::assertSame(0, $this->stats->snapshot(DashboardRange::preset('30'))->orders->periodCount);
        $this->order([[$door, '1']], OrderSource::Checkout);
        self::assertSame(0, $this->stats->snapshot(DashboardRange::preset('30'))->orders->periodCount, 'З кешу — ще старе значення');
        $custom = DashboardRange::fromQuery(null, (new DateTimeImmutable('-3 days'))->format('Y-m-d'), (new DateTimeImmutable('today'))->format('Y-m-d'));
        self::assertSame(1, $this->stats->snapshot($custom)->orders->periodCount, 'інший діапазон — інший ключ кешу, свіжі дані');
        $this->order([[$door, '1']], OrderSource::Checkout);
        self::assertSame(1, $this->stats->snapshot($custom)->orders->periodCount, 'довільний діапазон теж кешується');
        $this->stats->invalidate();
        self::assertSame(2, $this->stats->snapshot(DashboardRange::preset('30'))->orders->periodCount, 'invalidate скидає всі ключі, і пресети, і довільні');
        self::assertSame(2, $this->stats->snapshot($custom)->orders->periodCount);
    }

    public function testCustomRangeBoundsInclusiveAndGrouping(): void
    {
        $door = $this->product('DB-RANGE', 'Діапазон', 100_000, '10');
        $this->em->flush();
        $inside = $this->order([[$door, '1']], OrderSource::Checkout);
        $this->backdate($inside, '-10 days');           // 23:59 останнього дня — включно
        $this->db->executeStatement('UPDATE sales_order SET placed_at = ? WHERE id = ?', [(new DateTimeImmutable('-10 days'))->format('Y-m-d 23:59:59'), $inside->getId()]);
        $before = $this->order([[$door, '2']], OrderSource::Checkout);
        $this->db->executeStatement('UPDATE sales_order SET placed_at = ? WHERE id = ?', [(new DateTimeImmutable('-16 days'))->format('Y-m-d 23:59:59'), $before->getId()]);
        $after = $this->order([[$door, '3']], OrderSource::Checkout);
        $this->db->executeStatement('UPDATE sales_order SET placed_at = ? WHERE id = ?', [(new DateTimeImmutable('-9 days'))->format('Y-m-d 00:00:00'), $after->getId()]);

        $range = DashboardRange::fromQuery(null, (new DateTimeImmutable('-15 days'))->format('Y-m-d'), (new DateTimeImmutable('-10 days'))->format('Y-m-d'));
        $s = $this->stats->snapshot($range);
        self::assertSame(1, $s->orders->periodCount, 'лише замовлення в межах [from 00:00, to 23:59:59]');
        self::assertSame(100_000, $s->orders->periodTotal->amount);
        self::assertCount(6, $s->salesByDay, 'по днях: 6 точок');
        self::assertSame((new DateTimeImmutable('-10 days'))->format('Y-m-d'), $s->salesByDay[5]->date);
        self::assertSame(100, $s->salesByDay[5]->percent);
        self::assertSame(['DB-RANGE'], array_map(static fn ($t): string => $t->sku, $s->topByTotal));
        self::assertSame('1.000', $s->topByTotal[0]->quantity, 'топ теж у межах діапазону');

        // 120 днів — по тижнях з понеділка; 400 днів — по місяцях
        $weeks = $this->stats->snapshot(DashboardRange::fromQuery(null, (new DateTimeImmutable('-119 days'))->format('Y-m-d'), (new DateTimeImmutable('today'))->format('Y-m-d')));
        self::assertLessThanOrEqual(19, \count($weeks->salesByDay));
        self::assertGreaterThanOrEqual(17, \count($weeks->salesByDay));
        self::assertSame('1', (new DateTimeImmutable($weeks->salesByDay[1]->date))->format('N'), 'мітка тижня — понеділок');
        self::assertSame(3, array_sum(array_map(static fn ($p): int => $p->count, $weeks->salesByDay)), 'усі три замовлення в тижнях');
        $months = $this->stats->snapshot(DashboardRange::fromQuery(null, (new DateTimeImmutable('-399 days'))->format('Y-m-d'), (new DateTimeImmutable('today'))->format('Y-m-d')));
        self::assertCount(14, $months->salesByDay, '400 днів — 14 календарних місяців');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $months->salesByDay[0]->date);
        self::assertSame((new DateTimeImmutable('today'))->format('Y-m'), $months->salesByDay[13]->date);
    }

    private function product(string $sku, string $name, int $priceMinor, string $stock): Product
    {
        $product = new Product($sku, 'UAH');
        $product->translate('uk')->setName($name);
        $product->getDefaultVariant()->setPrice(Money::of($priceMinor, 'UAH'));
        $product->getDefaultVariant()->setStock($stock);
        $this->em->persist($product);

        return $product;
    }

    /** @param list<array{0: Product, 1: string}> $lines */
    private function order(array $lines, OrderSource $source): Order
    {
        return $this->placeOrder->place(new PlaceOrderRequest($source, 'uk', 'Покупець', '0501112233', array_map(static fn (array $l): array => ['variant' => $l[0]->getDefaultVariant(), 'quantity' => $l[1]], $lines)));
    }

    private function backdate(Order $order, string $modifier): void
    {
        $this->db->executeStatement('UPDATE sales_order SET placed_at = ? WHERE id = ?', [(new DateTimeImmutable($modifier))->format('Y-m-d H:i:s'), $order->getId()]);
    }
}
