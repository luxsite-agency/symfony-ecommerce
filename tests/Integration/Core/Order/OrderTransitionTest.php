<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Order;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\OrderNumberGenerator;
use App\Core\Order\OrderTransitionService;
use App\Core\Order\PhoneNormalizer;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\Exception\LogicException;

/** Workflow замовлення (ADR D6/D7): дозволені переходи, історія, повернення залишку лише при cancel; номери; телефон. */
final class OrderTransitionTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testHappyPathAndCancelRestoresStock(): void
    {
        $inStock = new Product('WF-1', 'UAH');
        $inStock->getDefaultVariant()->setPrice(Money::of(10_000, 'UAH'));
        $inStock->getDefaultVariant()->setStock('10');
        $madeToOrder = new Product('WF-2', 'UAH');
        $madeToOrder->getDefaultVariant()->setPrice(Money::of(5_000, 'UAH'));
        $madeToOrder->getDefaultVariant()->setStockPolicy(StockPolicy::MadeToOrder);
        $this->em->persist($inStock);
        $this->em->persist($madeToOrder);
        $this->em->flush();

        $order = self::getContainer()->get(PlaceOrder::class)->place(new PlaceOrderRequest(
            OrderSource::Checkout, 'uk', 'Тест', '0501112233',
            [['variant' => $inStock->getDefaultVariant(), 'quantity' => '3'], ['variant' => $madeToOrder->getDefaultVariant(), 'quantity' => '2']],
        ));
        self::assertSame('7.000', $this->variant('WF-1')->getStock());
        self::assertSame('0.000', $this->variant('WF-2')->getStock(), 'made_to_order не списується');
        self::assertSame(40_000, $order->getTotal()->amount);

        $transitions = self::getContainer()->get(OrderTransitionService::class);
        self::assertSame(['confirm', 'cancel'], $transitions->available($order));
        $transitions->apply($order, 'confirm', 'manager@example.test');
        self::assertSame('confirmed', $order->getStatus());
        self::assertNotNull($order->getConfirmedAt());
        $transitions->apply($order, 'process', 'manager@example.test');
        $transitions->apply($order, 'ship', 'manager@example.test');
        self::assertFalse($transitions->can($order, 'cancel'), 'Після відправки скасувати не можна');
        $transitions->apply($order, 'complete', 'manager@example.test');
        self::assertSame('completed', $order->getStatus());
        self::assertNotNull($order->getCompletedAt());
        self::assertSame(['place', 'confirm', 'process', 'ship', 'complete'], array_map(static fn ($h): string => $h->getTransition(), $order->getHistory()->toArray()));
        self::assertSame('7.000', $this->variant('WF-1')->getStock(), 'Виконане замовлення залишок не повертає');

        $this->expectException(LogicException::class);
        $transitions->apply($order, 'cancel');
    }

    public function testCancelReleasesReservedStockOnce(): void
    {
        $product = new Product('WF-3', 'UAH');
        $product->getDefaultVariant()->setPrice(Money::of(10_000, 'UAH'));
        $product->getDefaultVariant()->setStock('10');
        $this->em->persist($product);
        $this->em->flush();
        $order = self::getContainer()->get(PlaceOrder::class)->place(new PlaceOrderRequest(OrderSource::Quick, 'uk', 'Тест', '0501112233', [['variant' => $product->getDefaultVariant(), 'quantity' => '4']]));
        self::assertSame('6.000', $this->variant('WF-3')->getStock());

        $transitions = self::getContainer()->get(OrderTransitionService::class);
        $transitions->apply($order, 'confirm', 'manager@example.test');
        $transitions->apply($order, 'cancel', 'manager@example.test', 'Покупець передумав');
        self::assertSame('cancelled', $order->getStatus());
        self::assertNotNull($order->getCancelledAt());
        self::assertSame('10.000', $this->variant('WF-3')->getStock(), 'Резерв повернуто');
        $item = $order->getItems()->first();
        self::assertNotFalse($item);
        self::assertSame('0.000', $item->getReservedStock());
        $last = $order->getHistory()->last();
        self::assertNotFalse($last);
        self::assertSame('Покупець передумав', $last->getComment());
        self::assertSame('manager@example.test', $last->getActor());
    }

    /**
     * Консиліум PR #6 п. 3: два менеджери тиснуть «Скасувати» одночасно. Конкурент моделюється SQL-ом між читанням
     * замовлення і apply(): він уже скасував і повернув резерв. Стара реалізація застосовувала перехід до застарілого
     * стану в пам'яті й повертала резерв удруге (stock 14 замість 10); нова бере PESSIMISTIC_WRITE, перечитує стан
     * і відмовляє. Справжня двопроцесна перевірка — вручну: два curl POST …/transition з cancel у tmux одночасно.
     */
    public function testConcurrentCancelReleasesStockOnlyOnce(): void
    {
        $product = new Product('WF-4', 'UAH');
        $product->getDefaultVariant()->setPrice(Money::of(10_000, 'UAH'));
        $product->getDefaultVariant()->setStock('10');
        $this->em->persist($product);
        $this->em->flush();
        $order = self::getContainer()->get(PlaceOrder::class)->place(new PlaceOrderRequest(OrderSource::Quick, 'uk', 'Тест', '0501112233', [['variant' => $product->getDefaultVariant(), 'quantity' => '4']]));
        self::assertSame('6.000', $this->variant('WF-4')->getStock());
        $transitions = self::getContainer()->get(OrderTransitionService::class);
        self::assertTrue($transitions->can($order, 'cancel'), 'У пам’яті замовлення ще new');

        // конкурентний запит уже скасував замовлення і повернув резерв
        $db = $this->em->getConnection();
        $db->executeStatement("UPDATE sales_order SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?", [$order->getId()]);
        $db->executeStatement('UPDATE sales_order_item SET reserved_stock = 0 WHERE order_id = ?', [$order->getId()]);
        $db->executeStatement('UPDATE catalog_product_variant SET stock = stock + 4 WHERE sku = ?', ['WF-4']);

        try {
            $transitions->apply($order, 'cancel', 'second-manager@example.test');
            self::fail('Другий cancel має бути відхилений');
        } catch (LogicException) {
        }
        self::assertSame('cancelled', $order->getStatus(), 'Стан перечитано з БД під блокуванням');
        self::assertSame('10.000', $this->variant('WF-4')->getStock(), 'Резерв повернуто рівно один раз');
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM sales_order_status_history WHERE order_id = ?', [$order->getId()]), 'Історія без другого cancel');
    }

    public function testOrderNumbersAreSequentialPerPeriod(): void
    {
        $generator = self::getContainer()->get(OrderNumberGenerator::class);
        $now = new DateTimeImmutable('2026-09-27');
        $a = $generator->next($now);
        $b = $generator->next($now);
        self::assertMatchesRegularExpression('/^2609-\d{6}$/', $a);
        self::assertSame((int) substr($a, 5) + 1, (int) substr($b, 5));
        self::assertStringStartsWith('2610-', $generator->next(new DateTimeImmutable('2026-10-01')));
    }

    public function testPhoneNormalizer(): void
    {
        self::assertSame('+380671234567', PhoneNormalizer::normalize('067 123 45 67'));
        self::assertSame('+380671234567', PhoneNormalizer::normalize('+38 (067) 123-45-67'));
        self::assertSame('+380671234567', PhoneNormalizer::normalize('671234567'));
        self::assertSame('+48123456789', PhoneNormalizer::normalize('+48 123 456 789'));
        $this->expectException(InvalidArgumentException::class);
        PhoneNormalizer::normalize('12345');
    }

    /** Залишок з БД (refresh, не clear — замовлення має лишатися managed для apply() з блокуванням). */
    private function variant(string $sku): ProductVariant
    {
        $variant = $this->em->getRepository(ProductVariant::class)->findOneBy(['sku' => $sku]);
        self::assertNotNull($variant);
        $this->em->refresh($variant);

        return $variant;
    }
}
