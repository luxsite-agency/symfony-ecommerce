<?php

declare(strict_types=1);

namespace App\Tests\Core\Analytics;

use App\Core\Analytics\Ecommerce\EcommerceEventBuilder;
use App\Core\Analytics\Ecommerce\PurchaseDedup;
use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\Brand;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Enum\AttributeType;
use App\Core\Shared\Money\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/** Payload GA4 ecommerce: item_id = sku, ціна в грошових одиницях, value = Σ, purchase один раз на замовлення. */
final class EcommerceEventBuilderTest extends TestCase
{
    private EcommerceEventBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new EcommerceEventBuilder('uk');
    }

    public function testItemMapsSkuNamePriceBrandCategoryVariant(): void
    {
        $color = new Attribute('color', AttributeType::Select);
        $color->setVariantAxis(true);
        $white = $color->addValue('white');
        $white->translate('en')->setValue('White'); // en першим — регресія: брався перший переклад
        $white->translate('uk')->setValue('Білий');
        $doors = new Category();
        $doors->translate('uk')->setName('Двері');
        $product = new Product('ML-14-WHITE', 'UAH');
        $product->translate('uk')->setName('Двері ML 14');
        $product->translate('en')->setName('Door ML 14');
        $product->setBrand(new Brand('Solo', 'solo'));
        $product->addCategory($doors);
        $variant = $product->getDefaultVariant();
        $variant->setPrice(Money::of(426_250, 'UAH'));
        $variant->setAxisValue($white);

        $item = $this->builder->item($variant, '2', 3, 'Двері');
        self::assertSame([
            'item_id' => 'ML-14-WHITE',
            'item_name' => 'Двері ML 14',
            'price' => 4262.5,
            'quantity' => 2,
            'currency' => 'UAH',
            'item_brand' => 'Solo',
            'item_category' => 'Двері',
            'item_variant' => 'Білий',
            'index' => 3,
            'item_list_name' => 'Двері',
        ], $item);

        self::assertSame('Door ML 14', $this->builder->item($variant, 1, null, null, 'en')['item_name'], 'Назва мовою запиту');
        self::assertSame('White', $this->builder->item($variant, 1, null, null, 'en')['item_variant'], 'Варіант мовою запиту');
        self::assertSame('Білий', $this->builder->item($variant, 1, null, null, 'ru')['item_variant'], 'Без перекладу — мова за замовчуванням');
        self::assertSame('Двері ML 14', $this->builder->item($variant, 1, null, null, 'ru')['item_name'], 'Без перекладу — мова за замовчуванням');
        self::assertSame(1.5, $this->builder->item($variant, '1,5')['quantity'], 'Дробова кількість (метри) — число');
    }

    public function testEventsCarryValueAndCurrency(): void
    {
        $a = ['item_id' => 'A', 'item_name' => 'A', 'price' => 100.0, 'quantity' => 2, 'currency' => 'UAH'];
        $b = ['item_id' => 'B', 'item_name' => 'B', 'price' => 0.5, 'quantity' => 3, 'currency' => 'UAH'];

        $view = $this->builder->viewItem([$a]);
        self::assertSame('view_item', $view['event']);
        self::assertSame(['currency' => 'UAH', 'value' => 200.0, 'items' => [$a]], $view['ecommerce']);

        $cart = $this->builder->addToCart([$a, $b]);
        self::assertSame(201.5, $cart['ecommerce']['value']);
        self::assertSame('remove_from_cart', $this->builder->removeFromCart([$a])['event']);
        self::assertSame('view_cart', $this->builder->viewCart([$a])['event']);
        self::assertSame(['event' => 'begin_checkout'], array_intersect_key($this->builder->beginCheckout([$a]), ['event' => 1]));
        self::assertSame('nova_poshta', $this->builder->addShippingInfo([$a], 'nova_poshta')['ecommerce']['shipping_tier']);
        self::assertSame('cod', $this->builder->addPaymentInfo([$a], 'cod')['ecommerce']['payment_type']);

        $list = $this->builder->viewItemList([$a, $b], 'Міжкімнатні двері');
        self::assertSame('view_item_list', $list['event']);
        self::assertSame('Міжкімнатні двері', $list['ecommerce']['item_list_name']);
        self::assertSame('select_item', $this->builder->selectItem($a, 'Двері')['event']);

        $purchase = $this->builder->purchase('ORD-1001', [$a, $b], 12_000, 0, 'SALE10');
        self::assertSame('purchase', $purchase['event']);
        self::assertSame('ORD-1001', $purchase['ecommerce']['transaction_id']);
        self::assertSame(120.0, $purchase['ecommerce']['shipping']);
        self::assertSame(201.5, $purchase['ecommerce']['value']);
        self::assertSame('SALE10', $purchase['ecommerce']['coupon']);

        $lead = $this->builder->generateLead(50_000, 'UAH', 'quick_order');
        self::assertSame(['event' => 'generate_lead', 'currency' => 'UAH', 'value' => 500.0, 'lead_source' => 'quick_order'], $lead);
        self::assertArrayNotHasKey('value', $this->builder->generateLead());
    }

    public function testPurchaseDedupBySessionOrderNumber(): void
    {
        $requests = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requests->push($request);
        $dedup = new PurchaseDedup($requests);

        self::assertTrue($dedup->firstTime('ORD-1'));
        self::assertFalse($dedup->firstTime('ORD-1'), 'Перезавантаження «дякуємо» не дублює purchase');
        self::assertTrue($dedup->firstTime('ORD-2'));
    }
}
