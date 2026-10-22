<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Order;

use App\Core\Catalog\Entity\Product;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Mail\OrderMailer;
use App\Core\Order\Message\SendCustomerOrderEmail;
use App\Core\Order\Message\SendShopOrderEmail;
use App\Core\Order\MessageHandler\SendCustomerOrderEmailHandler;
use App\Core\Order\MessageHandler\SendShopOrderEmailHandler;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/**
 * Консиліум PR #6 п. 7: лист покупцю і лист магазину — два повідомлення з окремими хендлерами. Раніше один хендлер
 * слав обидва: збій на адресі магазину → retry → покупець отримував копії. Тепер ретрай лише «магазинного» повідомлення
 * покупця не зачіпає.
 */
final class OrderEmailsTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        self::getContainer()->get(Settings::class)->invalidate();
        parent::tearDown();
    }

    public function testCustomerAndShopEmailsAreIndependentMessages(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $product = new Product('EM-1', 'UAH');
        $product->getDefaultVariant()->setPrice(Money::of(10_000, 'UAH'));
        $product->getDefaultVariant()->setStock('5');
        $em->persist($product);
        $em->flush();
        self::getContainer()->get(Settings::class)->set(SettingKeys::ORDERS_NOTIFY_EMAIL, 'sales@example.test, boss@example.test');
        $order = self::getContainer()->get(PlaceOrder::class)->place(new PlaceOrderRequest(OrderSource::Quick, 'uk', 'Тест', '0501112233', [['variant' => $product->getDefaultVariant(), 'quantity' => '1']], email: 'buyer@example.test'));
        $mailer = self::getContainer()->get(OrderMailer::class);

        (new SendCustomerOrderEmailHandler($em, $mailer))(new SendCustomerOrderEmail((int) $order->getId()));
        self::assertEmailCount(1);
        self::assertEmailHeaderSame(self::getMailerMessage(0) ?? self::fail(), 'To', 'buyer@example.test');

        $shop = new SendShopOrderEmailHandler($em, $mailer);
        $shop(new SendShopOrderEmail((int) $order->getId()));
        self::assertEmailCount(3);
        $shop(new SendShopOrderEmail((int) $order->getId())); // retry «магазинного» повідомлення
        self::assertEmailCount(5);
        $toBuyer = 0;
        foreach (self::getMailerMessages() as $message) {
            self::assertInstanceOf(Email::class, $message);
            $toBuyer += 'buyer@example.test' === $message->getTo()[0]->getAddress() ? 1 : 0;
        }
        self::assertSame(1, $toBuyer, 'Ретрай листа магазину не дублює лист покупцю');
    }
}
