<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Entity\OptionValue;
use App\Core\Order\Entity\Order;
use App\Core\Order\Entity\OrderItem;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Shared\Money\Money;
use App\Site\Catalog\ProductPresenter;
use App\Tests\Core\Catalog\Option\DoorWithOptions;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Значення опцій за замовчуванням (рев'ю PR #15 v2, п. 1, 3, 4): ціна картки, мета й schema Offer — з ними й з акцією;
 * явне «без опції» (порожній radio, зняті чекбокси) не замінюється значенням за замовчуванням: картка = кошик = замовлення.
 */
final class ProductOptionsDefaultsTest extends WebTestCase
{
    public function testExplicitNoneSurvivesCartAndOrderAndCardPriceIncludesDefaults(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(3));
        $fx = new DoorWithOptions('-'.$suffix);
        // за замовчуванням: сосна (0), приховані петлі (+123), лиштва комплектом за ціною вибору (+450)
        $fx->hinges->markDefault($fx->hinges->findValue('hidden') ?? throw new LogicException());
        $fx->trimGroup->markDefault($fx->trimGroup->findValue('set') ?? throw new LogicException());
        $em->persist($fx->door);
        $em->persist($fx->trimProduct);
        $promotion = new Promotion('Двері −10 %', 'UAH');
        $promotion->setPercentDiscount('10');
        $promotion->addProduct($fx->door);
        $em->persist($promotion);
        $em->flush();
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $url = 'https://'.$host.'/door-'.$suffix;

        // п. 4: основна ціна, мета й Offer — з опціями за замовчуванням і акцією: (1000 + 123) × 0,9 = 1010,70 + 450 = 1460,70
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame('1460.70', $crawler->filter('[data-options-price]')->attr('data-options-price'));
        self::assertSame(['@type' => 'Offer', 'price' => '1460.70'], array_intersect_key(self::ld($crawler)['offers'] ?? [], array_flip(['@type', 'price'])));
        self::assertStringContainsString(Money::of(146_070, 'UAH')->format('uk'), $crawler->filter('p.price[data-variant-id]')->text(), 'основна ціна картки');
        $view = static::getContainer()->get(ProductPresenter::class)->present($fx->door, 'uk', [], null);
        self::assertSame(146_070, $view['price']?->amount, 'ця ж ціна йде в ProductSeo::meta ({price})');
        self::assertCount(1, $crawler->filter('[data-option-group="hinges"] input[type="hidden"][name="options[hinges][]"][value=""]'), 'маркер для знятих чекбоксів');
        // компонент із ціною вибору — доплата тим самим форматом, що й у модифікаторів (UX-аудит S7); панель «У кошик» — ціна з опціями
        self::assertStringContainsString('+'.Money::of(45_000, 'UAH')->format('uk'), $crawler->filter('[data-option-group="trim"] input[value="set"]')->closest('label')?->text() ?? '');
        self::assertSame(Money::of(146_070, 'UAH')->format('uk'), $crawler->filter('.buy-bar-price')->text());

        // «порахувати» з явним «без»: картка 1000 × 0,9 = 900
        $crawler = $client->request('GET', $url.'?calc=1&options[leaf]=pine&options[hinges][]=&options[trim]=');
        self::assertSame('900.00', $crawler->filter('[data-options-price]')->attr('data-options-price'));

        // у кошик: зняли «приховані петлі», обрали «без» лиштви — так відправляє форма без JS
        $crawler = $client->request('GET', $url);
        $form = $crawler->filter('form.add-to-cart')->form();
        $values = $form->getPhpValues();
        $values['options'] = ['leaf' => 'pine', 'hinges' => [''], 'trim' => ''];
        $values['quantity'] = '1';
        $client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertStringContainsString("900,00\u{a0}₴", $crawler->filter('.cart-line td')->eq(1)->text(), 'кошик = картка, не 1460,70');
        self::assertStringNotContainsString('hidden', $crawler->filter('.cart-line')->text());

        // п. 3: кошик показує перерахований снепшот — нова назва значення видно одразу
        $em = static::getContainer()->get(EntityManagerInterface::class); // ядро перезавантажується між запитами
        $pine = $em->find(OptionValue::class, $fx->leaf->findValue('pine')?->getId());
        self::assertNotNull($pine);
        $pine->translate('uk')->setLabel('Сосна масив');
        $em->flush();
        $crawler = $client->request('GET', 'https://'.$host.'/cart/');
        self::assertStringContainsString('Полотно: Сосна масив', $crawler->filter('.line-options')->text());

        $client->click($crawler->filter('a.btn:contains("Оформити замовлення")')->link());
        $client->submit($client->getCrawler()->filter('form.checkout')->form([
            'name' => 'Без опцій', 'phone' => '067 765 43 21', 'email' => 'none-'.$suffix.'@example.test',
            'shipping' => 'nova_poshta', 'city' => 'Київ', 'address' => 'Відділення 1', 'payment' => 'cod',
        ]));
        self::assertResponseRedirects();
        $em->clear();
        $order = $em->getRepository(Order::class)->findOneBy(['email' => 'none-'.$suffix.'@example.test']);
        self::assertNotNull($order);
        self::assertCount(1, $order->getItems(), 'без лиштви — без дочірнього рядка');
        $item = $order->getItems()->first();
        self::assertInstanceOf(OrderItem::class, $item);
        self::assertSame(90_000, $item->getUnitPrice()->amount, 'замовлення = кошик = картка');
        self::assertEquals(['leaf' => 'pine', 'hinges' => [], 'trim' => ''], $item->getOptionsSnapshot()['selection'] ?? null);
    }

    /** @return array<string, mixed> */
    private static function ld(Crawler $crawler): array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]')->each(static fn (Crawler $s): string => $s->text()) as $json) {
            $data = json_decode($json, true);
            if (\is_array($data) && 'Product' === ($data['@type'] ?? null)) {
                return $data;
            }
        }

        return [];
    }
}
