<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Order;

use App\Core\Catalog\Entity\Product;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Exception\PriceChangedException;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * E13 PR-4: PlaceOrder сам перечитує покупця за customerId і рахує ціни за його групою (лише активного), пише
 * customer_id і снепшот коду групи; роздрібна акція на групу без дозволу не діє; ціна картки швидкого замовлення
 * звіряється за тією ж групою. Невідомий id — гостьове замовлення.
 */
final class PlaceOrderCustomerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PlaceOrder $placeOrder;
    private Product $product;
    private CustomerGroup $wholesale;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->placeOrder = self::getContainer()->get(PlaceOrder::class);
        $this->wholesale = new CustomerGroup('wholesale_po_'.bin2hex(random_bytes(2)), 'Опт');
        $this->product = new Product('PO-C-'.bin2hex(random_bytes(2)), 'UAH');
        $this->product->getDefaultVariant()->setPrice(Money::of(10_000, 'UAH'));
        $this->product->getDefaultVariant()->setStock('50');
        $this->product->getDefaultVariant()->addPriceTier('1', Money::of(8_000, 'UAH'), $this->wholesale);
        $retailPromo = new Promotion('Роздріб −10 %', 'UAH');
        $retailPromo->setPercentDiscount('10');
        $retailPromo->addProduct($this->product);
        foreach ([$this->wholesale, $this->product, $retailPromo] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testWholesalerOrderUsesGroupPriceAndKeepsSnapshot(): void
    {
        $customer = $this->customer(true, $this->wholesale);
        $order = $this->placeOrder->place($this->request($customer->getId()));
        self::assertSame($customer->getId(), $order->getCustomer()?->getId());
        self::assertSame($this->wholesale->getCode(), $order->getCustomerGroupCode());
        $item = $order->getItems()->first();
        self::assertNotFalse($item);
        self::assertSame(8_000, $item->getUnitPrice()->amount, 'поріг групи, роздрібна акція не лягає');
        self::assertNull($item->getListPrice(), 'без акції — без «до акції»');
        self::assertSame(16_000, $order->getItemsTotal()->amount);

        // група змінилась після замовлення — снепшот лишається
        $customer->setCustomerGroup(null);
        $this->em->flush();
        self::assertSame($this->wholesale->getCode(), $order->getCustomerGroupCode());
    }

    public function testGuestAndCustomerWithoutGroupPayRetailWithPromotion(): void
    {
        $guest = $this->placeOrder->place($this->request(null));
        self::assertNull($guest->getCustomer());
        self::assertNull($guest->getCustomerGroupCode());
        self::assertSame(9_000, $guest->getItems()->first()->getUnitPrice()->amount);

        $retail = $this->customer(true, null);
        $order = $this->placeOrder->place($this->request($retail->getId(), key: 'K-retail'));
        self::assertSame($retail->getId(), $order->getCustomer()?->getId());
        self::assertNull($order->getCustomerGroupCode());
        self::assertSame(9_000, $order->getItems()->first()->getUnitPrice()->amount);

        self::assertNull($this->placeOrder->place($this->request(999_999_999, key: 'K-unknown'))->getCustomer(), 'невідомий id — гість');
    }

    public function testInactiveCustomerLosesGroupPrice(): void
    {
        $inactive = $this->customer(false, $this->wholesale);
        $order = $this->placeOrder->place($this->request($inactive->getId()));
        self::assertSame($inactive->getId(), $order->getCustomer()?->getId(), 'замовлення прив\'язане');
        self::assertNull($order->getCustomerGroupCode(), 'але група вимкненого не діє');
        self::assertSame(9_000, $order->getItems()->first()->getUnitPrice()->amount);
    }

    public function testQuickOrderCardPriceIsCheckedAgainstGroupPrice(): void
    {
        $customer = $this->customer(true, $this->wholesale);
        $variant = $this->product->getDefaultVariant();
        $seenRetail = new PlaceOrderRequest(OrderSource::Quick, 'uk', 'Тест', '0501112233', [['variant' => $variant, 'quantity' => '1', 'card_price' => 9_000]], customerId: $customer->getId());
        try {
            $this->placeOrder->place($seenRetail);
            self::fail('Очікувалась PriceChangedException');
        } catch (PriceChangedException $e) {
            self::assertSame(8_000, $e->total->amount, 'оптовику показується його ціна');
        }
        $seenGroup = new PlaceOrderRequest(OrderSource::Quick, 'uk', 'Тест', '0501112233', [['variant' => $variant, 'quantity' => '1', 'card_price' => 8_000]], customerId: $customer->getId());
        self::assertSame(8_000, $this->placeOrder->place($seenGroup)->getItems()->first()->getUnitPrice()->amount);
    }

    private function customer(bool $active, ?CustomerGroup $group): Customer
    {
        $customer = new Customer('po-'.bin2hex(random_bytes(3)).'@example.test', 'Покупець', 'uk');
        $customer->verifyEmail('$2y$04$hash');
        $customer->setCustomerGroup($group);
        $customer->setActive($active);
        $this->em->persist($customer);
        $this->em->flush();

        return $customer;
    }

    private function request(?int $customerId, string $key = 'K1'): PlaceOrderRequest
    {
        return new PlaceOrderRequest(OrderSource::Checkout, 'uk', 'Тест', '0501112233', [['variant' => $this->product->getDefaultVariant(), 'quantity' => '2']], idempotencyKey: $key.'-'.bin2hex(random_bytes(2)), customerId: $customerId);
    }
}
