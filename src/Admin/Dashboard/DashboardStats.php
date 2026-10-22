<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Dashboard;

use App\Core\Analytics\AnalyticsTags;
use App\Core\Integration\Crm\CrmConnectorRegistry;
use App\Core\Integration\Entity\ExternalRef;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\OrderStatus;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Агрегати дашборда адмінки: лише DBAL-запити по індексах (placed_at; status+placed_at; stock_policy+active+stock;
 * queue_name у messenger_messages; entity_type+entity_id у external_ref), без гідрації сутностей. Результат кешується
 * в пулі cache.dashboard на app.dashboard_cache_ttl секунд окремо для кожного діапазону дат (ключ from_to).
 * Періоди замовлень — [start, endExclusive): параметри, не інтерполяція. Графік: по днях, тижнях або місяцях (DashboardRange::granularity).
 */
final class DashboardStats
{
    // v2: у снепшоті з'явились одиниці й шкала графіка — старі записи кешу не десеріалізуються в нові класи
    private const string CACHE_PREFIX = 'admin.dashboard.v2.';

    public function __construct(
        private readonly Connection $db,
        #[Autowire(service: 'cache.dashboard')] private readonly CacheInterface&AdapterInterface $cache,
        private readonly CrmConnectorRegistry $crm,
        private readonly Settings $settings,
        private readonly AnalyticsTags $analyticsTags,
        #[Autowire('%app.default_currency%')] private readonly string $currency,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
        #[Autowire('%app.dashboard_cache_ttl%')] private readonly int $cacheTtl = 120,
        #[Autowire('%app.dashboard_low_stock_threshold%')] private readonly string $lowStockThreshold = '5',
    ) {
    }

    public function snapshot(DashboardRange $range, ?DateTimeImmutable $now = null): DashboardSnapshot
    {
        return $this->cache->get(self::CACHE_PREFIX.$range->cacheKey(), function (ItemInterface $item) use ($range, $now): DashboardSnapshot {
            $item->expiresAfter($this->cacheTtl);

            return $this->build($range, $now ?? new DateTimeImmutable());
        });
    }

    /** Скинути кеш усіх діапазонів (тести; після масових змін): ключів довільно багато, тож за префіксом. */
    public function invalidate(): void
    {
        $this->cache->clear(self::CACHE_PREFIX);
    }

    private function build(DashboardRange $range, DateTimeImmutable $now): DashboardSnapshot
    {
        $from = $range->start();
        $until = $range->endExclusive();
        [$outOfStock, $lowStock, $alerts] = $this->stock();
        [$quickCount, $quick] = $this->quickRequests();
        [$sales, $ticks] = $this->salesByDay($range);

        return new DashboardSnapshot(
            $range,
            $now,
            $this->orders($from, $until, $now),
            $sales,
            $this->topProducts($from, $until, 's'),
            $this->topProducts($from, $until, 'q'),
            $this->recentOrders(),
            $quickCount,
            $quick,
            $outOfStock,
            $lowStock,
            $alerts,
            $this->integrations($from, $until),
            $this->analytics(),
            ...[...$this->pendingReviews(), $ticks],
        );
    }

    /**
     * Відгуки на модерації: лічильник і останні 10 (рішення власника 13.10.2026).
     *
     * @return array{0: int, 1: list<PendingReview>}
     */
    private function pendingReviews(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT r.id, r.rating, r.text, r.created_at, c.name AS customer_name, COALESCE(t.name, v.sku) AS product_name
             FROM catalog_product_review r JOIN customer c ON c.id = r.customer_id
             LEFT JOIN catalog_product_translation t ON t.product_id = r.product_id AND t.locale = ?
             LEFT JOIN catalog_product_variant v ON v.product_id = r.product_id AND v.position = 0
             WHERE r.status = ? ORDER BY r.created_at DESC, r.id DESC LIMIT 10',
            [$this->defaultLocale, 'pending'],
        );

        return [
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_product_review WHERE status = ?', ['pending']),
            array_map(static fn (array $r): PendingReview => new PendingReview(
                (int) $r['id'],
                (string) ($r['product_name'] ?? ''),
                (int) $r['rating'],
                (string) $r['customer_name'],
                mb_strimwidth((string) $r['text'], 0, 120, '…'),
                new DateTimeImmutable((string) $r['created_at']),
            ), $rows),
        ];
    }

    private function orders(DateTimeImmutable $from, DateTimeImmutable $until, DateTimeImmutable $now): OrdersSummary
    {
        $today = $now->setTime(0, 0);
        /** @var array{d1: int|string|null, d7: int|string|null, d30: int|string|null} $counts */
        $counts = $this->db->fetchAssociative(
            'SELECT SUM(placed_at >= ?) AS d1, SUM(placed_at >= ?) AS d7, COUNT(*) AS d30 FROM sales_order WHERE placed_at >= ? AND status <> ?',
            [$this->sql($today), $this->sql($today->modify('-6 days')), $this->sql($today->modify('-29 days')), OrderStatus::Cancelled->value],
        ) ?: ['d1' => 0, 'd7' => 0, 'd30' => 0];
        /** @var array{c: int|string, s: int|string|null} $period */
        $period = $this->db->fetchAssociative(
            'SELECT COUNT(*) AS c, COALESCE(SUM(total_minor), 0) AS s FROM sales_order WHERE placed_at >= ? AND placed_at < ? AND status <> ?',
            [$this->sql($from), $this->sql($until), OrderStatus::Cancelled->value],
        ) ?: ['c' => 0, 's' => 0];
        $count = (int) $period['c'];
        $total = (int) $period['s'];

        $byStatus = array_fill_keys(array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::cases()), 0);
        foreach ($this->db->fetchAllKeyValue('SELECT status, COUNT(*) FROM sales_order WHERE placed_at >= ? AND placed_at < ? GROUP BY status', [$this->sql($from), $this->sql($until)]) as $status => $n) {
            $byStatus[(string) $status] = (int) $n;
        }

        return new OrdersSummary(
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM sales_order WHERE status = ?', [OrderStatus::New->value]),
            (int) $counts['d1'],
            (int) $counts['d7'],
            (int) $counts['d30'],
            $count,
            Money::of($total, $this->currency),
            Money::of($count > 0 ? intdiv($total, $count) : 0, $this->currency),
            $byStatus,
        );
    }

    /**
     * Графік: сума й кількість по днях з БД, далі групування за кроком діапазону (день, тиждень з понеділка, місяць),
     * щоб два роки не давали 731 стовпчик. Мітка точки — перший день групи (місяць — YYYY-MM).
     *
     * @return array{0: list<DayPoint>, 1: list<Money>} точки і позначки осі сум
     */
    private function salesByDay(DashboardRange $range): array
    {
        $rows = $this->db->fetchAllAssociativeIndexed(
            'SELECT DATE(placed_at) AS d, COUNT(*) AS c, COALESCE(SUM(total_minor), 0) AS s FROM sales_order WHERE placed_at >= ? AND placed_at < ? AND status <> ? GROUP BY d',
            [$this->sql($range->start()), $this->sql($range->endExclusive()), OrderStatus::Cancelled->value],
        );
        $granularity = $range->granularity();
        /** @var array<string, array{int, int}> $groups ключ → [кількість, сума] */
        $groups = [];
        for ($day = $range->start(); $day < $range->endExclusive(); $day = $day->modify('+1 day')) {
            $key = match ($granularity) {
                'month' => $day->format('Y-m'),
                'week' => $day->modify('monday this week')->format('Y-m-d'),
                default => $day->format('Y-m-d'),
            };
            $iso = $day->format('Y-m-d');
            $groups[$key] = [($groups[$key][0] ?? 0) + (int) ($rows[$iso]['c'] ?? 0), ($groups[$key][1] ?? 0) + (int) ($rows[$iso]['s'] ?? 0)];
        }
        $max = max([0, ...array_map(static fn (array $g): int => $g[1], $groups)]);
        $step = self::axisStep($max);
        $top = $step * max(1, (int) ceil($max / $step));
        $points = [];
        foreach ($groups as $key => [$count, $sum]) {
            $points[] = new DayPoint((string) $key, $count, Money::of($sum, $this->currency), (int) round(100 * $sum / $top));
        }

        return [$points, array_map(fn (int $minor): Money => Money::of($minor, $this->currency), range(0, $top, $step))];
    }

    /** Крок шкали в копійках: «кругла» сума цілих гривень (1, 2, 5 × 10ⁿ), щоб до максимуму вмістилося не більше 3 кроків. */
    public static function axisStep(int $maxMinor): int
    {
        $raw = max(1, (int) ceil($maxMinor / 100)) / 3;
        $magnitude = 10 ** (int) floor(log10($raw));
        foreach ([1, 2, 5, 10] as $m) {
            if ($m * $magnitude >= $raw) {
                return max(1, (int) round($m * $magnitude)) * 100;
            }
        }

        return 100; // недосяжно: 10 × 10ⁿ ≥ raw завжди
    }

    /** @return list<TopProduct> */
    private function topProducts(DateTimeImmutable $from, DateTimeImmutable $until, string $orderBy): array
    {
        $rows = $this->db->fetchAllAssociative(
            \sprintf('SELECT i.sku, i.name, SUM(i.quantity) AS q, COALESCE(SUM(i.line_total_minor), 0) AS s, MAX(v.unit) AS u FROM sales_order_item i INNER JOIN sales_order o ON o.id = i.order_id LEFT JOIN catalog_product_variant v ON v.id = i.variant_id WHERE o.placed_at >= ? AND o.placed_at < ? AND o.status <> ? AND i.parent_id IS NULL GROUP BY i.sku, i.name ORDER BY %s DESC, i.sku LIMIT 10', 'q' === $orderBy ? 'q' : 's'),
            [$this->sql($from), $this->sql($until), OrderStatus::Cancelled->value],
        );

        return array_map(fn (array $r): TopProduct => new TopProduct((string) $r['sku'], (string) $r['name'], bcadd((string) $r['q'], '0', 3), Money::of((int) $r['s'], $this->currency), null === $r['u'] ? null : (string) $r['u']), $rows);
    }

    /** @return list<RecentOrder> */
    private function recentOrders(): array
    {
        return $this->orderRows('SELECT id, number, status, source, customer_name, phone, total_minor, currency, placed_at FROM sales_order ORDER BY placed_at DESC, id DESC LIMIT 10', []);
    }

    /** @return array{0: int, 1: list<RecentOrder>} */
    private function quickRequests(): array
    {
        $params = [OrderStatus::New->value, OrderSource::Quick->value];

        return [
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM sales_order WHERE status = ? AND source = ?', $params),
            $this->orderRows('SELECT id, number, status, source, customer_name, phone, total_minor, currency, placed_at FROM sales_order WHERE status = ? AND source = ? ORDER BY placed_at DESC, id DESC LIMIT 10', $params),
        ];
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<RecentOrder>
     */
    private function orderRows(string $sql, array $params): array
    {
        return array_map(static fn (array $r): RecentOrder => new RecentOrder(
            (int) $r['id'],
            (string) $r['number'],
            OrderStatus::from((string) $r['status']),
            OrderSource::from((string) $r['source']),
            (string) $r['customer_name'],
            (string) $r['phone'],
            Money::of((int) $r['total_minor'], (string) $r['currency']),
            new DateTimeImmutable((string) $r['placed_at']),
        ), $this->db->fetchAllAssociative($sql, $params));
    }

    /** @return array{0: int, 1: int, 2: list<StockAlert>} */
    private function stock(): array
    {
        $where = "v.stock_policy = 'in_stock' AND v.active = 1 AND p.active = 1 AND v.stock <= ?";
        /** @var array{o: int|string|null, l: int|string|null} $counts */
        $counts = $this->db->fetchAssociative(
            'SELECT COALESCE(SUM(v.stock <= 0), 0) AS o, COALESCE(SUM(v.stock > 0), 0) AS l FROM catalog_product_variant v INNER JOIN catalog_product p ON p.id = v.product_id WHERE '.$where,
            [$this->lowStockThreshold],
        ) ?: ['o' => 0, 'l' => 0];
        $rows = $this->db->fetchAllAssociative(
            'SELECT v.id, v.product_id, v.sku, v.stock, v.unit, t.name FROM catalog_product_variant v INNER JOIN catalog_product p ON p.id = v.product_id LEFT JOIN catalog_product_translation t ON t.product_id = p.id AND t.locale = ? WHERE '.$where.' ORDER BY v.stock ASC, v.sku LIMIT 10',
            [$this->defaultLocale, $this->lowStockThreshold],
        );

        return [(int) $counts['o'], (int) $counts['l'], array_map(static fn (array $r): StockAlert => new StockAlert((int) $r['id'], (int) $r['product_id'], (string) $r['sku'], null === $r['name'] ? null : (string) $r['name'], bcadd((string) $r['stock'], '0', 3), (string) $r['unit']), $rows)];
    }

    private function integrations(DateTimeImmutable $from, DateTimeImmutable $until): IntegrationStatus
    {
        $connector = $this->crm->active();
        $pushOrders = $connector->capabilities()->pushOrder;
        $lastOrder = $this->db->fetchOne('SELECT MAX(created_at) FROM integration_external_ref WHERE entity_type = ?', [ExternalRef::TYPE_ORDER]);
        $lastCatalog = $this->db->fetchOne('SELECT MAX(created_at) FROM integration_external_ref WHERE entity_type IN (?)', [[ExternalRef::TYPE_PRODUCT, ExternalRef::TYPE_PRODUCT_VARIANT]], [ArrayParameterType::STRING]);
        $withoutRef = $pushOrders ? (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM sales_order o LEFT JOIN integration_external_ref r ON r.entity_type = ? AND r.entity_id = o.id AND r.system_code = ? WHERE o.placed_at >= ? AND o.placed_at < ? AND o.status <> ? AND r.id IS NULL',
            [ExternalRef::TYPE_ORDER, $connector->getSystem(), $this->sql($from), $this->sql($until), OrderStatus::Cancelled->value],
        ) : 0;
        $failed = 0;
        $pending = 0;
        $oldest = null;
        foreach ($this->db->fetchAllAssociative('SELECT queue_name, COUNT(*) AS c, MIN(available_at) AS oldest FROM messenger_messages WHERE delivered_at IS NULL GROUP BY queue_name') as $row) {
            if ('failed' === $row['queue_name']) {
                $failed += (int) $row['c'];
            } else {
                $pending += (int) $row['c'];
                $candidate = new DateTimeImmutable((string) $row['oldest']);
                $oldest = null === $oldest || $candidate < $oldest ? $candidate : $oldest;
            }
        }

        return new IntegrationStatus(
            $connector->getSystem(),
            $pushOrders,
            \is_string($lastOrder) ? new DateTimeImmutable($lastOrder) : null,
            \is_string($lastCatalog) ? new DateTimeImmutable($lastCatalog) : null,
            $withoutRef,
            $failed,
            $pending,
            $oldest,
        );
    }

    private function analytics(): AnalyticsStatus
    {
        $set = fn (string $key): bool => '' !== trim((string) $this->settings->get($key));

        return new AnalyticsStatus($set(SettingKeys::GTM_CONTAINER_ID), $set(SettingKeys::GA4_MEASUREMENT_ID), $set(SettingKeys::FB_PIXEL_ID), $this->analyticsTags->isEnabled());
    }

    private function sql(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }
}
