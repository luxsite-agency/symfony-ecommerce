<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Order\Entity\Order;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Ціна фіксується на момент оформлення, але не мовчки (рішення власника 08.10.2026): якщо між сторінкою checkout
 * (чи карткою для швидкого замовлення) і submit ціна змінилась — замовлення не створюється, покупець бачить пояснення
 * з новою сумою й підтверджує ще раз; збіг — замовлення одразу.
 */
final class CheckoutPriceConfirmTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $host;
    private Product $product;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->host = 'https://'.$host;
        $this->product = new Product('PCF-1', 'UAH');
        $this->product->translate('uk')->setName('Двері ціна')->setSlug('dveri-tsina');
        $this->product->getDefaultVariant()->setPrice(Money::of(100_000, 'UAH'));
        $this->product->getDefaultVariant()->setStock('10');
        $this->em->persist($this->product);
        $this->em->flush();
    }

    public function testPromotionEndedBetweenCheckoutAndSubmitAsksToConfirmNewTotal(): void
    {
        $promotion = new Promotion('Тиждень дверей', 'UAH');
        $promotion->setPercentDiscount('10');
        $promotion->addProduct($this->product);
        $this->em->persist($promotion);
        $this->em->flush();

        $crawler = $this->checkoutWithInCart('2');
        self::assertStringContainsString("1\u{a0}800,00\u{a0}₴", $crawler->filter('.checkout-total')->text(), 'Акція діє: 2 × 900');

        // поки покупець заповнював форму, акція закінчилась
        $promotion = $this->em->find(Promotion::class, $promotion->getId());
        self::assertNotNull($promotion);
        $promotion->setEndsAt(new DateTimeImmutable('-1 minute'));
        $this->em->flush();

        $crawler = $this->submitCheckout($crawler);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->orders(), 'Замовлення за старою ціною не створено');
        $notice = $crawler->filter('[data-price-changed]')->text();
        self::assertStringContainsString('закінчилась акція', $notice);
        self::assertStringContainsString(Money::of(200_000, 'UAH')->format('uk'), $notice, 'Нова сума в поясненні');
        self::assertStringContainsString("2\u{a0}000,00\u{a0}₴", $crawler->filter('.checkout-total')->text(), 'Підсумок — за новою ціною');
        self::assertSame('Олена', $crawler->filter('form.checkout input[name="name"]')->attr('value'), 'Введене не загубилось');
        self::assertStringContainsString('Підтвердити з новою сумою', $crawler->filter('form.checkout button[type="submit"]')->text());

        // підтвердження нової суми — замовлення за ціною на момент оформлення, без знижки
        $this->submitCheckout($crawler);
        self::assertResponseRedirects();
        $order = $this->lastOrder();
        self::assertSame(200_000, $order->getTotal()->amount);
        $item = $order->getItems()->first();
        self::assertNotFalse($item);
        self::assertSame(100_000, $item->getUnitPrice()->amount);
        self::assertNull($item->getPromotionName());
    }

    public function testPriceChangedInAdminBetweenCheckoutAndSubmitAsksToConfirm(): void
    {
        $crawler = $this->checkoutWithInCart('1');
        self::assertStringContainsString("1\u{a0}000,00\u{a0}₴", $crawler->filter('.checkout-total')->text());

        $this->changePrice(120_000); // менеджер підняв ціну в адмінці

        $crawler = $this->submitCheckout($crawler);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->orders());
        self::assertStringContainsString(Money::of(120_000, 'UAH')->format('uk'), $crawler->filter('[data-price-changed]')->text());

        $this->submitCheckout($crawler);
        self::assertResponseRedirects();
        self::assertSame(120_000, $this->lastOrder()->getTotal()->amount);
    }

    public function testOtherFormErrorStillWarnsAboutChangedPrice(): void
    {
        $crawler = $this->checkoutWithInCart('1');
        $this->changePrice(120_000);

        // помилка в телефоні: форма повертається, ціна вже нова — пояснення має бути, а не мовчазна нова сума
        $crawler = $this->client->submit($crawler->filter('form.checkout')->form([
            'name' => 'Олена', 'phone' => '12', 'shipping' => 'nova_poshta', 'city' => 'Київ', 'address' => 'Відділення 1', 'payment' => 'cod',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->orders());
        self::assertStringContainsString(Money::of(120_000, 'UAH')->format('uk'), $crawler->filter('[data-price-changed]')->text());

        $this->submitCheckout($crawler);
        self::assertResponseRedirects();
        self::assertSame(120_000, $this->lastOrder()->getTotal()->amount);
    }

    public function testMatchingPricePlacesOrderAtOnce(): void
    {
        $crawler = $this->checkoutWithInCart('3');
        $this->submitCheckout($crawler);
        self::assertResponseRedirects();
        self::assertSame(300_000, $this->lastOrder()->getTotal()->amount);
    }

    public function testForgedOrMissingFingerprintNeverPlacesAtClientPrice(): void
    {
        $crawler = $this->checkoutWithInCart('1');
        $form = $crawler->filter('form.checkout')->form(['name' => 'Олена', 'phone' => '0671234567', 'city' => 'Київ', 'address' => 'Відділення 1']);
        $form['price']->setValue(''); // стара закешована форма без відбитка
        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->orders());
        self::assertCount(1, $crawler->filter('[data-price-changed]'));
    }

    public function testQuickOrderPriceChangedReturnsToCardWithNewPrice(): void
    {
        $crawler = $this->client->request('GET', $this->host.'/dveri-tsina');
        $form = $crawler->filter('form.quick-order')->form(['name' => 'Іван', 'phone' => '+380501112233', 'quantity' => '1']);

        $this->changePrice(90_000);

        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertSame(0, $this->orders(), 'Швидке замовлення за старою ціною не створено');
        $crawler = $this->client->followRedirect();
        $flash = $crawler->filter('.flash-error')->text();
        self::assertStringContainsString('Ціна змінилась', $flash);
        self::assertStringContainsString(Money::of(90_000, 'UAH')->format('uk'), $flash);

        // на картці вже нова ціна — повторне замовлення проходить
        $this->client->submit($crawler->filter('form.quick-order')->form(['name' => 'Іван', 'phone' => '+380501112233', 'quantity' => '1']));
        self::assertResponseRedirects();
        self::assertSame(90_000, $this->lastOrder()->getTotal()->amount);
    }

    private function checkoutWithInCart(string $quantity): Crawler
    {
        $crawler = $this->client->request('GET', $this->host.'/dveri-tsina');
        $this->client->submit($crawler->filter('form.add-to-cart')->form(['quantity' => $quantity]));
        $crawler = $this->client->request('GET', $this->host.'/checkout/');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function submitCheckout(Crawler $crawler): Crawler
    {
        return $this->client->submit($crawler->filter('form.checkout')->form([
            'name' => 'Олена', 'phone' => '0671234567', 'shipping' => 'nova_poshta', 'city' => 'Київ', 'address' => 'Відділення 1', 'payment' => 'cod',
        ]));
    }

    private function changePrice(int $minor): void
    {
        $variant = $this->em->getRepository(ProductVariant::class)->findOneBy(['sku' => 'PCF-1']);
        self::assertNotNull($variant);
        $variant->setPrice(Money::of($minor, 'UAH'));
        $this->em->flush();
    }

    private function orders(): int
    {
        return $this->em->getRepository(Order::class)->count([]);
    }

    private function lastOrder(): Order
    {
        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($order);

        return $order;
    }
}
