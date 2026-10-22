<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Order;

use App\Core\Catalog\Option\OptionPriceCalculator;
use App\Core\Order\Entity\Order;
use App\Core\Order\Entity\OrderItem;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Exception\OrderValidationException;
use App\Core\Order\Mail\OrderMailer;
use App\Core\Order\Message\SendCustomerOrderEmail;
use App\Core\Order\MessageHandler\SendCustomerOrderEmailHandler;
use App\Core\Order\OrderExportFactory;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Tests\Core\Catalog\Option\DoorWithOptions;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/** Опції в замовленні (ADR Rev 6/9): ціна рядка фіксується калькулятором, снепшот у рядку, компоненти — дочірні рядки з резервом. */
final class PlaceOrderOptionsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DoorWithOptions $fx;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->fx = new DoorWithOptions('-'.bin2hex(random_bytes(3)));
        $this->em->persist($this->fx->door);
        $this->em->persist($this->fx->trimProduct);
        $this->em->flush();
    }

    public function testOrderFixesPriceSnapshotAndReservesComponents(): void
    {
        $snapshot = self::getContainer()->get(OptionPriceCalculator::class)->configure($this->fx->variant, '2', ['leaf' => 'oak', 'height' => '2100', 'trim' => 'kit'], 'uk')?->snapshot;
        $order = $this->place([['variant' => $this->fx->variant, 'quantity' => '2', 'options' => $snapshot]]);

        // 1000 + 15 % + 0,50 × 100 мм = 1200; + лиштва 2,5 × 200 = 1700 за одиницю
        self::assertSame(2 * 170_000, $order->getTotal()->amount, 'сума — по батьківських рядках, компоненти вже в них');
        /** @var list<OrderItem> $items */
        $items = $order->getItems()->toArray();
        self::assertCount(2, $items);
        [$main, $child] = $items;
        self::assertSame(170_000, $main->getUnitPrice()->amount);
        self::assertSame('Двері (Полотно: Дуб; Висота: 2100 мм; trim: kit)', $main->getName());
        self::assertSame($snapshot['selection'] ?? null, $main->getOptionsSnapshot()['selection'] ?? null);
        self::assertSame($main, $child->getParent());
        self::assertSame($this->fx->trim->getSku(), $child->getSku());
        self::assertSame('5.000', $child->getQuantity());
        self::assertSame('5.000', $child->getReservedStock());
        self::assertSame(0, $child->getUnitPrice()->amount, 'компонент неоплачуваний: ціна вже в батьківському рядку');
        $export = self::getContainer()->get(OrderExportFactory::class)->fromOrder($order);
        self::assertSame([$main->getSku(), $child->getSku()], array_column($export->items, 'sku'), 'у CRM і компонент — для резерву SKU (D7)');
        self::assertSame([170_000, 0], array_column($export->items, 'unitPriceMinor'), 'компонент з ціною 0');
        self::assertSame(['2.000', '5.000'], array_column($export->items, 'quantity'));
        self::assertSame($order->getItemsTotal()->amount, array_sum(array_column($export->items, 'lineTotalMinor')));

        $this->em->refresh($this->fx->trim);
        self::assertSame('95.000', $this->fx->trim->getStock());
    }

    public function testEmailsListComponentsAsIncludedWithoutPrice(): void
    {
        $snapshot = self::getContainer()->get(OptionPriceCalculator::class)->configure($this->fx->variant, '1', ['leaf' => 'pine', 'trim' => 'set'], 'uk')?->snapshot;
        $order = $this->place([['variant' => $this->fx->variant, 'quantity' => '1', 'options' => $snapshot]], null, 'buyer@example.test');
        (new SendCustomerOrderEmailHandler($this->em, self::getContainer()->get(OrderMailer::class)))(new SendCustomerOrderEmail((int) $order->getId()));
        $message = self::getMailerMessage(0);
        self::assertInstanceOf(Email::class, $message);

        // двері 1000 + лиштва за ціною вибору 450 = 1450; рядок лиштви — «у складі» без ціни
        foreach (['text' => (string) $message->getTextBody(), 'html' => (string) $message->getHtmlBody()] as $kind => $body) {
            self::assertStringContainsString('У складі', $body, $kind);
            self::assertStringContainsString("1\u{a0}450,00", $body, $kind);
            self::assertStringNotContainsString('500,00', $body, $kind.': каталожна ціна лиштви 2,5 × 200 не виводиться');
            self::assertStringNotContainsString("450,00\u{a0}₴", str_replace("1\u{a0}450,00\u{a0}₴", '', $body), $kind.': і ціна вибору окремим рядком теж');
        }
    }

    public function testDefaultsWithoutSelectionAndRequiredGroupError(): void
    {
        $order = $this->place([['variant' => $this->fx->variant, 'quantity' => '1']]);
        self::assertSame(100_000, $order->getTotal()->amount, 'без вибору — pine за замовчуванням');
        $item = $order->getItems()->first();
        self::assertInstanceOf(OrderItem::class, $item);
        self::assertSame(['leaf' => 'pine'], $item->getOptionsSnapshot()['selection'] ?? null);

        $this->fx->leaf->markDefault($this->fx->leaf->findValue('pine') ?? throw new LogicException(), false);
        $this->em->flush();
        try {
            $this->place([['variant' => $this->fx->variant, 'quantity' => '1']], 'k2');
            self::fail('Очікувалась OrderValidationException');
        } catch (OrderValidationException $e) {
            self::assertStringContainsString('leaf', $e->errors['lines'] ?? '');
        }
    }

    public function testComponentOutOfStockRollsBack(): void
    {
        $this->fx->trim->setStock('1');
        $this->em->flush();
        $snapshot = self::getContainer()->get(OptionPriceCalculator::class)->configure($this->fx->variant, '1', ['trim' => 'kit'], 'uk')?->snapshot;
        try {
            $this->place([['variant' => $this->fx->variant, 'quantity' => '1', 'options' => $snapshot]]);
            self::fail('Очікувалась OrderValidationException');
        } catch (OrderValidationException) {
            self::addToAssertionCount(1);
        }
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(Order::class)->count(['phone' => '+380501112233']));
        self::assertSame('10.000', $this->em->find($this->fx->variant::class, $this->fx->variant->getId())?->getStock(), 'резерв головного товару відкочено');
    }

    /** @param list<array{variant: \App\Core\Catalog\Entity\ProductVariant, quantity: string, options?: array<string, mixed>|null}> $lines */
    private function place(array $lines, ?string $key = null, ?string $email = null): Order
    {
        return self::getContainer()->get(PlaceOrder::class)->place(new PlaceOrderRequest(OrderSource::Checkout, 'uk', 'Тест', '0501112233', $lines, email: $email, idempotencyKey: $key));
    }
}
