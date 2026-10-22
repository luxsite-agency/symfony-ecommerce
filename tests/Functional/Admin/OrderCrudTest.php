<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Catalog\Entity\Product;
use App\Core\Order\Entity\Order;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\PlaceOrder;
use App\Core\Order\PlaceOrderRequest;
use App\Core\Security\Enum\UserRole;
use App\Core\Shared\Money\Money;

/** Адмінка замовлень: список, перегляд з рядками й історією, кнопки переходів лише дозволені, перехід із CSRF. */
final class OrderCrudTest extends AdminWebTestCase
{
    public function testDetailShowsItemsAndTransitionButtonsWork(): void
    {
        $product = new Product('ADM-1', 'UAH');
        $product->translate('uk')->setName('Двері адмін');
        $product->getDefaultVariant()->setPrice(Money::of(100_000, 'UAH'));
        $product->getDefaultVariant()->setStock('3');
        $this->em()->persist($product);
        $this->em()->flush();
        $order = static::getContainer()->get(PlaceOrder::class)->place(new PlaceOrderRequest(OrderSource::Quick, 'uk', 'Покупець', '0501112233', [['variant' => $product->getDefaultVariant(), 'quantity' => '2']]));

        $this->loginAs(UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl('/order'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($order->getNumber(), $crawler->filter('table tbody')->text());
        self::assertStringContainsString("2\u{a0}000,00\u{a0}₴", $crawler->filter('table tbody')->text());

        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/order/%d', $order->getId())));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ADM-1', $crawler->filter('table.datagrid')->text());
        self::assertStringContainsString('Двері адмін', $crawler->filter('table.datagrid')->text());
        $buttons = $crawler->filter('button[data-transition]')->each(static fn ($n): string => (string) $n->attr('data-transition'));
        self::assertEqualsCanonicalizing(['confirm', 'cancel'], $buttons, 'Лише дозволені Workflow переходи');

        $this->client->submit($crawler->filter('button[data-transition="confirm"]')->form());
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Підтверджене', $crawler->filter('body')->text());
        self::assertEqualsCanonicalizing(['process', 'cancel'], $crawler->filter('button[data-transition]')->each(static fn ($n): string => (string) $n->attr('data-transition')));
        self::assertStringContainsString('user@example.test', $crawler->filter('.list-unstyled')->text(), 'Історія з актором');

        // без CSRF — 403
        $this->client->request('POST', $this->adminUrl(\sprintf('/order/%d/transition', $order->getId())), ['transition' => 'cancel', 'token' => 'bad']);
        self::assertResponseStatusCodeSame(403);

        $this->em()->clear();
        self::assertSame('confirmed', $this->em()->find(Order::class, $order->getId())?->getStatus());
    }
}
