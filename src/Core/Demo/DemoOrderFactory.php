<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Demo;

use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Customer\Entity\Customer;
use App\Core\Order\Entity\Order;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Enum\PaymentMethod;
use App\Core\Order\Enum\ShippingMethod;
use App\Core\Order\Minimum\OrderMinimum;
use App\Core\Order\OrderNumberGenerator;
use App\Core\Order\OrderTransitionService;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Core\Order\StockReservation;
use App\Core\Pricing\Pricing;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Демо-замовлення через штатний PlaceOrder і OrderTransitionService (ціни, резерви, історія статусів як у покупця), але з
 * шиною, що нічого не відправляє: без CRM і листів. Позначки для purge: коментар «DEMO #n:», телефон +3800000000nn.
 * Дата розкидається по останніх ~180 днях детерміновано (daysAgo у плані), щоб дашборд мав що показати: placed_at
 * оновлюється прямим UPDATE, бо сутність дату не змінює. Замовлення покупця (customerId) рахується за його групою.
 *
 * @phpstan-type OrderPlan array{name: string, source: OrderSource, shipping: ShippingMethod, payment: PaymentMethod, city: ?string, address: ?string, lines: list<array{string, int, string}>, transitions: list<string>, daysAgo: int}
 */
final class DemoOrderFactory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly OrderNumberGenerator $orderNumbers,
        private readonly StockReservation $stock,
        private readonly Pricing $pricing,
        private readonly OrderTransitionService $transitions,
        private readonly OrderMinimum $minimum,
        private readonly DemoLocales $locales,
        #[Autowire('%app.default_currency%')] private readonly string $currency,
    ) {
    }

    public function exists(int $n): bool
    {
        $exists = $this->em->createQueryBuilder()->select('COUNT(o.id)')->from(Order::class, 'o')
            ->where('o.comment LIKE :mark')->setParameter('mark', addcslashes(self::mark($n), '%_').'%')->getQuery()->getSingleScalarResult();

        return 0 !== (int) $exists;
    }

    /**
     * @param OrderPlan $spec
     *
     * @return Order|null null — демо-товар видалено вручну (замовлення пропущено) або PlaceOrder відмовив (нема залишку)
     */
    public function place(int $n, array $spec, ?Customer $customer = null): ?Order
    {
        $lines = [];
        foreach ($spec['lines'] as [$productKey, $variantIndex, $quantity]) {
            $variant = $this->variant($productKey, $variantIndex);
            if (null === $variant) {
                return null;
            }
            $lines[] = ['variant' => $variant, 'quantity' => $quantity];
        }
        $silentBus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return new Envelope($message, $stamps);
            }
        };
        $placeOrder = new PlaceOrder($this->em, $this->orderNumbers, $this->stock, $this->pricing, $silentBus, $this->minimum, $this->locales->default());
        try {
            $order = $placeOrder->place(new PlaceOrderRequest(
                $spec['source'],
                $customer?->getLocale() ?? $this->locales->default(),
                $spec['name'],
                \sprintf('%s%02d', DemoCatalog::ORDER_PHONE_PREFIX, $n),
                $lines,
                $customer?->getEmail() ?? \sprintf('demo%d@example.com', $n),
                self::mark($n).' тестове замовлення демо-даних, не обробляти.',
                $spec['shipping'],
                $spec['city'],
                $spec['address'],
                $spec['payment'],
                'demo-seed-order-'.$n,
                customerId: $customer?->getId(),
            ));
        } catch (RuntimeException|InvalidArgumentException|DomainException) {
            return null; // напр. залишку вже нема: демо не має падати через ручні зміни каталогу
        }
        foreach ($spec['transitions'] as $transition) {
            $this->transitions->apply($order, $transition, 'demo-seed', 'Демо-дані');
        }
        if ($spec['daysAgo'] > 0) {
            $placed = new DateTimeImmutable(\sprintf('-%d days 11:%02d', $spec['daysAgo'], ($n * 7) % 60));
            $this->db->executeStatement('UPDATE sales_order SET placed_at = ?, updated_at = ? WHERE id = ?', [$placed->format('Y-m-d H:i:s'), $placed->format('Y-m-d H:i:s'), $order->getId()]);
            $this->db->executeStatement('UPDATE sales_order_status_history SET at = ? WHERE order_id = ?', [$placed->format('Y-m-d H:i:s'), $order->getId()]);
        }

        return $order;
    }

    public static function mark(int $n): string
    {
        return \sprintf('%s #%d:', DemoCatalog::ORDER_MARK, $n);
    }

    public function variant(string $productKey, int $index = 0): ?ProductVariant
    {
        $sku = DemoCatalog::SKU_PREFIX.$productKey;
        /** @var ProductVariant|null $variant */
        $variant = $this->em->createQueryBuilder()->select('v')->from(ProductVariant::class, 'v')
            ->where('v.sku = :sku OR v.sku LIKE :prefix')->setParameter('sku', $sku)->setParameter('prefix', addcslashes($sku, '%_').'-%')
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if (null === $variant) {
            return null;
        }
        $candidate = $variant->getProduct()->getVariants()->get($index);

        return $candidate instanceof ProductVariant && $candidate->getCurrency() === $this->currency ? $candidate : null;
    }
}
