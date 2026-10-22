<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Enum\AttributeType;
use App\Core\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Підпис варіанта в селекті «в кошик», швидкому замовленні й рядку кошика — мовою сторінки. Регресія: значення осі
 * бралось із першого перекладу (на uk-сторінці «Белый»), а в підписі стояло зайве « — ».
 */
final class VariantLabelLocaleTest extends WebTestCase
{
    public function testVariantSelectUsesPageLocale(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(3));

        $color = new Attribute('color_'.$suffix, AttributeType::Select);
        $color->setVariantAxis(true);
        $em->persist($color);
        $white = $color->addValue('white');
        $white->translate('ru')->setValue('Белый'); // ru першим — саме так «перший переклад» давав ru на uk-сторінці
        $white->translate('uk')->setValue('Білий');
        $black = $color->addValue('black');
        $black->translate('ru')->setValue('Черный');
        $black->translate('uk')->setValue('Чорний');

        $product = new Product('VL-'.$suffix, 'UAH');
        $product->translate('uk')->setName('Двері')->setSlug('vl-uk-'.$suffix);
        $product->translate('ru')->setName('Двери')->setSlug('vl-ru-'.$suffix);
        $first = $product->getDefaultVariant();
        $first->setAxisValue($white);
        $first->setPrice(Money::of(420_000, 'UAH'));
        $first->setStock('3');
        $second = $product->addVariant('VL-'.$suffix.'-B');
        $second->setAxisValue($black);
        $second->setPrice(Money::of(440_000, 'UAH'));
        $second->setStock('3');
        $em->persist($product);
        $em->flush();

        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $base = 'https://'.$host;

        $uk = $client->request('GET', $base.'/vl-uk-'.$suffix);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $uk->filter('form.add-to-cart select[name="variant"]'), 'варіант обирають осі — select у формі «в кошик» не дублює їх');
        $labels = self::options($uk, 'form.quick-order');
        self::assertSame(['Білий, '.Money::of(420_000, 'UAH')->format('uk'), 'Чорний, '.Money::of(440_000, 'UAH')->format('uk')], $labels);
        foreach ($labels as $label) {
            self::assertStringNotContainsString('Белый', $label);
            self::assertStringNotContainsString(' — ', $label);
        }

        $ru = $client->request('GET', $base.'/ru/vl-ru-'.$suffix);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('Белый, ', self::options($ru, 'form.quick-order')[0]);

        // рядок кошика — теж мовою сторінки
        $client->submit($uk->filter('form.add-to-cart')->form());
        $cart = $client->followRedirect();
        self::assertStringContainsString('Білий', $cart->filter('.cart-line .meta')->text());
        self::assertStringNotContainsString('Белый', $cart->filter('.cart-line .meta')->text());
    }

    /** @return list<string> */
    private static function options(Crawler $crawler, string $form): array
    {
        return $crawler->filter($form.' select[name="variant"] option')->each(static fn (Crawler $o): string => trim($o->text()));
    }
}
