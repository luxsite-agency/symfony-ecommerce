<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Order\Entity\Order;
use App\Core\Order\Entity\OrderItem;
use App\Tests\Core\Catalog\Option\DoorWithOptions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/** Опції на картці без JS (ADR Rev 5/9): форма, «порахувати» (GET), додавання в кошик з опціями, помилки вибору. */
final class ProductOptionsTest extends WebTestCase
{
    public function testCalculateAddToCartAndErrorsWithoutJs(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(3));
        $fx = new DoorWithOptions('-'.$suffix);
        $em->persist($fx->door);
        $em->persist($fx->trimProduct);
        $em->flush();
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $url = 'https://'.$host.'/door-'.$suffix;

        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form.add-to-cart [data-option-group="leaf"] input[value="pine"][checked]'), 'default відмічено');
        self::assertCount(1, $crawler->filter('input[type="number"][name="options[height]"][min="1800"][max="2400"][step="10"]'));
        self::assertCount(0, $crawler->filter('form.quick-order'), 'товар з опціями — лише через кошик');
        self::assertStringContainsString('+15 %', $crawler->filter('[data-option-group="leaf"]')->text());

        // «порахувати» — GET на картку з options[…]
        $crawler = $client->request('GET', $url.'?calc=1&options[leaf]=oak&options[height]=2100&options[trim]=kit');
        self::assertSame('1700.00', $crawler->filter('[data-options-price]')->attr('data-options-price'));
        self::assertCount(1, $crawler->filter('[data-option-group="leaf"] input[value="oak"][checked]'));

        $crawler = $client->request('GET', $url.'?calc=1&options[leaf]=oak&options[height]=2105');
        self::assertStringContainsString('Крок 10', $crawler->filter('[data-option-group="height"] .flash-error')->text());

        // у кошик: ціна рядка з опціями, опції видно в кошику
        $crawler = $client->request('GET', $url);
        $form = $crawler->filter('form.add-to-cart')->form();
        $leaf = $form['options[leaf]'];
        self::assertInstanceOf(ChoiceFormField::class, $leaf);
        $leaf->select('oak');
        $form['options[height]'] = '2100';
        $form['quantity'] = '2';
        $client->submit($form);
        $crawler = $client->followRedirect();
        self::assertCount(1, $crawler->filter('.cart-line'));
        self::assertStringContainsString('Полотно: Дуб', $crawler->filter('.line-options')->text());
        self::assertStringContainsString('Висота: 2100 мм', $crawler->filter('.line-options')->text());
        self::assertStringContainsString("2\u{a0}400,00\u{a0}₴", $crawler->filter('.cart-total')->text(), '2 × (1000 + 150 + 50)');

        // checkout: вибір із кошика фіксується в замовленні (ціна рядка й снепшот). Поля форми — як у CheckoutTest.
        $client->click($crawler->filter('a.btn:contains("Оформити замовлення")')->link());
        $crawler = $client->getCrawler();
        $client->submit($crawler->filter('form.checkout')->form([
            'name' => 'Опції Тест', 'phone' => '067 765 43 21', 'email' => 'options-'.$suffix.'@example.test',
            'shipping' => 'nova_poshta', 'city' => 'Київ', 'address' => 'Відділення 1', 'payment' => 'cod',
        ]));
        self::assertResponseRedirects();
        $em->clear();
        $order = $em->getRepository(Order::class)->findOneBy(['email' => 'options-'.$suffix.'@example.test']);
        self::assertNotNull($order);
        $item = $order->getItems()->first();
        self::assertInstanceOf(OrderItem::class, $item);
        self::assertSame(120_000, $item->getUnitPrice()->amount);
        // форма шле всі групи: необраний radio «без» і порожній маркер чекбоксів — явне «без опції»
        self::assertEquals(['height' => '2100', 'hinges' => [], 'leaf' => 'oak', 'trim' => ''], $item->getOptionsSnapshot()['selection'] ?? null, 'порядок ключів JSON MySQL не зберігає');
        self::assertSame(240_000, $order->getTotal()->amount);

        // невалідний вибір у POST — флеш з помилкою, рядка не додано
        $crawler = $client->request('GET', $url);
        $form = $crawler->filter('form.add-to-cart')->form();
        $form['options[height]'] = '9999';
        $client->submit($form);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Від 1800 до 2400', $crawler->filter('.flash-error')->text());
        $crawler = $client->request('GET', 'https://'.$host.'/cart/');
        self::assertCount(0, $crawler->filter('.cart-line'), 'після замовлення кошик порожній, невалідне не додалось');
    }
}
