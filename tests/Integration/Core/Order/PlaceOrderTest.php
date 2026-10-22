<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Order;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Order\Entity\Order;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Exception\OrderAlreadyPlacedException;
use App\Core\Order\Exception\OrderValidationException;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Валідація і гонки PlaceOrder (консиліум PR #6 пп. 4, 5, 10). */
final class PlaceOrderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PlaceOrder $placeOrder;
    private Product $product;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->placeOrder = self::getContainer()->get(PlaceOrder::class);
        $this->product = new Product('PO-1', 'UAH');
        $this->product->getDefaultVariant()->setPrice(Money::of(1_001, 'UAH'));
        $this->product->getDefaultVariant()->setStock('20');
        $this->em->persist($this->product);
        $this->em->flush();
    }

    /** п. 10: email довший за колонку (180) — помилка валідації, а не 500 від MySQL. */
    public function testEmailLongerThanColumnIsAValidationError(): void
    {
        try {
            $this->placeOrder->place($this->request(email: str_repeat('a', 170).'@example.com'));
            self::fail('Очікувалась OrderValidationException');
        } catch (OrderValidationException $e) {
            self::assertArrayHasKey('email', $e->errors);
        }
        self::assertSame(0, $this->em->getRepository(Order::class)->count([]));
    }

    /** п. 5: quantityStep перевіряється — 2.7 при кроці 0.5 від 2 не проходить, 2.5 проходить. */
    public function testQuantityStepIsEnforced(): void
    {
        $this->product->getDefaultVariant()->setQuantityRules('2', '0.5');
        $this->em->flush();
        try {
            $this->placeOrder->place($this->request(quantity: '2.7'));
            self::fail('Очікувалась OrderValidationException');
        } catch (OrderValidationException $e) {
            self::assertStringContainsString('кратна 0.500', $e->errors['lines'] ?? '');
        }
        $order = $this->placeOrder->place($this->request(quantity: '2.5'));
        $item = $order->getItems()->first();
        self::assertNotFalse($item);
        self::assertSame('2.500', $item->getQuantity());
    }

    /**
     * п. 4: подвійний сабміт наздоганяє паралельний запит уже після перевірки ідемпотентності. Конкурент моделюється
     * prePersist-слухачем: перед INSERT нашого замовлення ключ K2 «отримує» вже створене замовлення. Раніше
     * UniqueConstraintViolationException пробивалась у контролер (500); тепер — OrderAlreadyPlacedException з токеном
     * того замовлення, і резерв другого списання відкочено.
     */
    public function testIdempotencyRaceYieldsExistingOrderToken(): void
    {
        $first = $this->placeOrder->place($this->request(quantity: '3', key: 'K1'));
        $db = $this->em->getConnection();
        self::assertSame('17.000', $db->fetchOne('SELECT stock FROM catalog_product_variant WHERE sku = ?', ['PO-1']));

        $fired = false;
        $this->em->getEventManager()->addEventListener(Events::prePersist, new class($db, (int) $first->getId(), $fired) {
            public function __construct(private readonly \Doctrine\DBAL\Connection $db, private readonly int $firstId, private bool &$fired)
            {
            }

            public function prePersist(PrePersistEventArgs $args): void
            {
                if ($args->getObject() instanceof Order && !$this->fired) {
                    $this->fired = true;
                    $this->db->executeStatement('UPDATE sales_order SET idempotency_key = ? WHERE id = ?', [PlaceOrder::idempotencyKeyFor('K2', '+380501112233'), $this->firstId]);
                }
            }
        });

        try {
            $this->placeOrder->place($this->request(quantity: '3', key: 'K2'));
            self::fail('Очікувалась OrderAlreadyPlacedException');
        } catch (OrderAlreadyPlacedException $e) {
            self::assertSame($first->getSuccessToken(), $e->successToken, 'Токен «дякуємо» замовлення конкурента');
        }
        self::assertTrue($fired, 'Конкурент спрацював перед INSERT');
        // EM після збою flush закритий — перевіряємо DBAL-ом
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM sales_order'));
        self::assertSame('17.000', $db->fetchOne('SELECT stock FROM catalog_product_variant WHERE sku = ?', ['PO-1']), 'Друге списання відкочено');
    }

    /** Low: той самий ключ форми з іншим телефоном — інше замовлення, а не чужа «дякуємо»; з тим самим — те саме замовлення. */
    public function testIdempotencyKeyIsBoundToPhone(): void
    {
        $first = $this->placeOrder->place($this->request(key: 'shared-key'));
        $sameCustomer = $this->placeOrder->place($this->request(key: 'shared-key'));
        self::assertSame($first->getId(), $sameCustomer->getId());
        $other = $this->placeOrder->place($this->request(key: 'shared-key', phone: '0679998877'));
        self::assertNotSame($first->getId(), $other->getId());
        self::assertNotSame($first->getSuccessToken(), $other->getSuccessToken());
        self::assertSame(2, $this->em->getRepository(Order::class)->count([]));
    }

    /** Low: суми у BIGINT — 5 100 × 4 262,00 UAH = 21 736 200,00 UAH не вміщалось в INT (500 у STRICT-режимі). */
    public function testLargeOrderTotalIsStored(): void
    {
        $this->product->getDefaultVariant()->setPrice(Money::of(426_200, 'UAH'));
        $this->product->getDefaultVariant()->setStockPolicy(StockPolicy::MadeToOrder);
        $this->em->flush();
        $order = $this->placeOrder->place($this->request(quantity: '5100'));
        self::assertSame(2_173_620_000, $order->getTotal()->amount);
        $db = $this->em->getConnection();
        self::assertSame(2_173_620_000, (int) $db->fetchOne('SELECT total_minor FROM sales_order WHERE id = ?', [$order->getId()]));
        self::assertSame(2_173_620_000, (int) $db->fetchOne('SELECT line_total_minor FROM sales_order_item WHERE order_id = ?', [$order->getId()]));
    }

    /** Ф3: акція на товар — рядок замовлення зі зниженою ціною і снепшотом ціни до акції та назви. */
    public function testPromotionIsSnapshottedInOrderItem(): void
    {
        $promotion = new Promotion('Осінь −10 %', 'UAH');
        $promotion->setPercentDiscount('10');
        $promotion->addProduct($this->product);
        $this->em->persist($promotion);
        $this->em->flush();

        $order = $this->placeOrder->place($this->request(quantity: '2'));
        $item = $order->getItems()->first();
        self::assertNotFalse($item);
        self::assertSame(901, $item->getUnitPrice()->amount, '10.01 − 10 % = 9.009 → 9.01');
        self::assertSame(1_001, $item->getListPrice()?->amount);
        self::assertSame(100, $item->getDiscount()?->amount);
        self::assertSame('Осінь −10 %', $item->getPromotionName());
        self::assertSame(1_802, $order->getTotal()->amount);
        $db = $this->em->getConnection();
        $row = $db->fetchAssociative('SELECT list_price_minor, promotion_name FROM sales_order_item WHERE order_id = ?', [$order->getId()]) ?: [];
        self::assertSame([1001, 'Осінь −10 %'], [(int) ($row['list_price_minor'] ?? 0), $row['promotion_name'] ?? null]);
    }

    private function request(string $quantity = '1', ?string $email = null, ?string $key = null, string $phone = '0501112233'): PlaceOrderRequest
    {
        return new PlaceOrderRequest(OrderSource::Checkout, 'uk', 'Тест', $phone, [['variant' => $this->product->getDefaultVariant(), 'quantity' => $quantity]], email: $email, idempotencyKey: $key);
    }
}
