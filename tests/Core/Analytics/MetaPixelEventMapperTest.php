<?php

declare(strict_types=1);

namespace App\Tests\Core\Analytics;

use App\Core\Analytics\Ecommerce\MetaPixelEventMapper;
use PHPUnit\Framework\TestCase;

/** GA4 → Meta Pixel: content_ids = item_id (SKU варіанта), contents, value/currency без змін, event_id для CAPI. */
final class MetaPixelEventMapperTest extends TestCase
{
    private MetaPixelEventMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new MetaPixelEventMapper();
    }

    public function testViewItemBecomesViewContentWithVariantSku(): void
    {
        $meta = $this->mapper->map(['event' => 'view_item', 'ecommerce' => [
            'currency' => 'UAH', 'value' => 4400.0,
            'items' => [['item_id' => 'DOOR-B', 'item_name' => 'Двері', 'price' => 4400.0, 'quantity' => 1, 'currency' => 'UAH']],
        ]]);

        self::assertNotNull($meta);
        self::assertSame('ViewContent', $meta['event']);
        self::assertSame([
            'content_ids' => ['DOOR-B'],
            'content_type' => 'product',
            'contents' => [['id' => 'DOOR-B', 'quantity' => 1, 'item_price' => 4400.0]],
            'currency' => 'UAH',
            'value' => 4400.0,
        ], $meta['params']);
        self::assertMatchesRegularExpression('/^viewcontent\.[0-9a-f]{16}$/', $meta['event_id']);
        self::assertNotSame($meta['event_id'], $this->mapper->map(['event' => 'view_item', 'ecommerce' => ['items' => [['item_id' => 'DOOR-B']]]])['event_id'] ?? null, 'Кожна подія — свій id');
    }

    public function testCheckoutAndPurchaseCarryNumItemsAndOrderNumberAsEventId(): void
    {
        $items = [
            ['item_id' => 'A', 'price' => 100.0, 'quantity' => 2],
            ['item_id' => 'CLOTH', 'price' => 10.0, 'quantity' => 2.5], // дробова кількість (метри) → один рядок на суму
        ];
        $checkout = $this->mapper->map(['event' => 'begin_checkout', 'ecommerce' => ['currency' => 'UAH', 'value' => 225.0, 'items' => $items]]);
        self::assertNotNull($checkout);
        self::assertSame('InitiateCheckout', $checkout['event']);
        self::assertSame([['id' => 'A', 'quantity' => 2, 'item_price' => 100.0], ['id' => 'CLOTH', 'quantity' => 1, 'item_price' => 25.0]], $checkout['params']['contents']);
        self::assertSame(3, $checkout['params']['num_items']);
        self::assertSame(225.0, $checkout['params']['value']);

        $purchase = $this->mapper->map(['event' => 'purchase', 'ecommerce' => ['currency' => 'UAH', 'value' => 225.0, 'transaction_id' => '2610-000042', 'items' => $items]]);
        self::assertNotNull($purchase);
        self::assertSame('Purchase', $purchase['event']);
        self::assertSame('2610-000042', $purchase['event_id'], 'Детермінований: номер замовлення');
        self::assertSame('2610-000042', $purchase['params']['order_id']);
        self::assertSame(['A', 'CLOTH'], $purchase['params']['content_ids']);
        self::assertSame(225.0, $purchase['params']['value']);
        self::assertSame(3, $purchase['params']['num_items']);
    }

    public function testAddToCartAndPriceOnRequest(): void
    {
        $meta = $this->mapper->map(['event' => 'add_to_cart', 'ecommerce' => ['currency' => 'EUR', 'items' => [['item_id' => 'REQ-1', 'quantity' => 3]]]]);
        self::assertNotNull($meta);
        self::assertSame('AddToCart', $meta['event']);
        self::assertSame([['id' => 'REQ-1', 'quantity' => 3]], $meta['params']['contents'], 'Ціна за запитом — без item_price');
        self::assertArrayNotHasKey('value', $meta['params'], 'Без value, а не 0');
        self::assertSame('EUR', $meta['params']['currency']);
    }

    public function testEventsWithoutMetaCounterpartAreSkipped(): void
    {
        foreach (['view_item_list', 'select_item', 'view_cart', 'remove_from_cart', 'add_shipping_info', 'add_payment_info', 'generate_lead'] as $name) {
            self::assertNull($this->mapper->map(['event' => $name, 'ecommerce' => ['items' => [['item_id' => 'A']]]]), $name);
        }
        self::assertNull($this->mapper->map(['event' => 'view_item', 'ecommerce' => ['items' => []]]), 'Без товарів');
        self::assertNull($this->mapper->map(['event' => 'purchase', 'ecommerce' => ['items' => [['item_id' => 'A']]]]), 'Purchase без номера замовлення');
    }
}
