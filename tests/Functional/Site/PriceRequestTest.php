<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Cart\Entity\Cart;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Order\Entity\Order;
use App\Core\Order\Enum\OrderSource;
use App\Core\Order\Message\PushOrderToCrm;
use App\Core\Order\Message\SendCustomerOrderEmail;
use App\Core\Order\Message\SendShopOrderEmail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** «Ціна за запитом»: на картці замість кошика — «Запитати ціну»; запит = лід (замовлення price_request, ціна 0) у CRM. */
final class PriceRequestTest extends WebTestCase
{
    public function testPriceRequestInsteadOfCart(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(3));
        $product = new Product('PR-'.$suffix, 'UAH');
        $product->translate('uk')->setName('Годинник з емблемою')->setSlug('pr-'.$suffix);
        $product->setPriceMode(PriceMode::OnRequest);
        $product->getDefaultVariant()->setStock('5');
        $em->persist($product);
        $em->flush();
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $url = 'https://'.$host.'/pr-'.$suffix;

        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form.add-to-cart'), 'купити не можна');
        self::assertCount(0, $crawler->filter('form.quick-order'));
        self::assertCount(1, $crawler->filter('form.price-request'));
        self::assertStringContainsString('Ціна за запитом', $crawler->filter('.price')->text());

        // stateless CSRF: чужий сайт і сусідній піддомен (same-site) — відмова, лід не створюється
        $variantId = (string) $crawler->filter('form.price-request input[name="variant"]')->attr('value');
        $lead = ['variant' => $variantId, 'name' => 'Бот', 'phone' => '067 999 88 77', '_token' => 'csrf-token'];
        foreach ([['HTTP_ORIGIN' => 'https://evil.example'], ['HTTP_SEC_FETCH_SITE' => 'same-site'], []] as $server) {
            $client->restart(); // без Referer з історії клієнта
            $client->request('POST', 'https://'.$host.'/price-request', $lead, server: $server);
            self::assertResponseStatusCodeSame(403, json_encode($server) ?: '');
        }
        self::assertNull($em->getRepository(Order::class)->findOneBy(['phone' => '+380679998877']));

        $crawler = $client->request('GET', $url);
        $client->submit($crawler->filter('form.price-request')->form(['name' => 'Іван', 'phone' => '067 111 22 33', 'comment' => '2 шт, з гравіюванням']));
        self::assertResponseRedirects($url);
        // outbox — до followRedirect (наступний запит перезавантажує ядро й in-memory транспорт)
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = array_map(static fn ($e): string => $e->getMessage()::class, $transport->getSent());
        self::assertContains(PushOrderToCrm::class, $sent, 'лід — у CRM');
        self::assertContains(SendShopOrderEmail::class, $sent);
        self::assertNotContains(SendCustomerOrderEmail::class, $sent);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Запит надіслано', $crawler->filter('.flash-success')->text());

        $em->clear();
        $order = $em->getRepository(Order::class)->findOneBy(['phone' => '+380671112233', 'source' => OrderSource::PriceRequest]);
        self::assertNotNull($order);
        self::assertSame(0, $order->getTotal()->amount);
        self::assertSame('Запит ціни: 2 шт, з гравіюванням', $order->getComment());
        $item = $order->getItems()->first();
        self::assertNotFalse($item);
        self::assertSame('0', rtrim(rtrim($item->getReservedStock(), '0'), '.'), 'без резерву');

        // кошик «ціну за запитом» не пропускає навіть в обхід форми
        $variant = $em->find(ProductVariant::class, $product->getDefaultVariant()->getId());
        self::assertNotNull($variant);
        $this->expectException(CatalogInvariantViolation::class);
        $this->expectExceptionMessage('за запитом');
        (new Cart(Cart::hashToken('pr'), 'UAH'))->addItem($variant, '1');
    }
}
