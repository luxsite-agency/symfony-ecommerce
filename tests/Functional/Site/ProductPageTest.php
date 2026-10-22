<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Media;
use App\Core\Catalog\Entity\Product;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Сторінка товару: варіанти за осями, ціна/наявність обраного варіанта, характеристики, зображення, hreflang, 404. */
final class ProductPageTest extends CatalogPageTestCase
{
    public function testProductRendersInEveryLocaleWithHreflang(): void
    {
        $p1 = $this->fx->p['P1'];
        foreach (self::locales() as $locale) {
            $crawler = $this->get($this->productUrl($p1, $locale));
            self::assertResponseIsSuccessful($locale);
            self::assertSame($locale.' P1', $crawler->filter('h1')->text());
            self::assertSame($this->productUrl($p1, $locale, [], UrlGeneratorInterface::ABSOLUTE_URL), $crawler->filter('link[rel="canonical"]')->attr('href'));
            self::assertStringContainsString($locale.' running', $crawler->filter('.crumbs')->text());

            $expected = self::expectedHreflang(fn (string $l): string => $this->productUrl($p1, $l, [], UrlGeneratorInterface::ABSOLUTE_URL));
            self::assertEquals($expected, self::hreflang($crawler));
        }
    }

    public function testCategoriesBlockLinksEveryActiveCategoryMainFirst(): void
    {
        $p1 = $this->fx->p['P1'];
        $p1->addCategory($this->fx->bags);
        $this->em()->flush();
        foreach (self::locales() as $locale) {
            $crawler = $this->get($this->productUrl($p1, $locale));
            self::assertResponseIsSuccessful($locale);
            self::assertSame($this->categoryUrl($this->fx->running, $locale), $crawler->filter('.product-category a')->attr('href'), 'помітне посилання на головну категорію');
            $links = $crawler->filter('.product-categories a')->each(static fn (Crawler $a): string => (string) $a->attr('href'));
            self::assertSame([$this->categoryUrl($this->fx->running, $locale), $this->categoryUrl($this->fx->bags, $locale)], $links);
            self::assertStringContainsString($locale.' sneakers', $crawler->filter('.product-categories li')->first()->text(), 'шлях предків перед назвою');
        }

        // після запиту клієнт перезавантажує ядро: беремо категорію з поточного EntityManager
        $bags = $this->em()->find(Category::class, $this->fx->bags->getId());
        self::assertNotNull($bags);
        $bags->setActive(false);
        $this->em()->flush();
        // лишилась одна активна — вона вже в крихтах і «Категорія: …», окремий блок не дублює її (UX-аудит S9)
        $crawler = $this->get($this->productUrl($p1, self::defaultLocale()));
        self::assertCount(0, $crawler->filter('.product-categories'), 'неактивна категорія не показується');
        self::assertSame($this->categoryUrl($this->fx->running, self::defaultLocale()), $crawler->filter('.product-category a')->attr('href'));
    }

    public function testAxisSelectionPicksVariantPriceAndStock(): void
    {
        $locale = self::defaultLocale();
        $p1 = $this->fx->p['P1'];
        $size = $this->fx->size->getCode();
        $color = $this->fx->color->getCode();

        $crawler = $this->get($this->productUrl($p1, $locale));
        self::assertSame((string) $this->fx->v['V1']->getId(), $crawler->filter('.price')->attr('data-variant-id'), 'без вибору — перший доступний');
        self::assertStringContainsString(Money::of(1000, 'UAH')->format($locale), $crawler->filter('.price')->text());
        self::assertSame(['s38', 's42'], $crawler->filter('[data-axis="'.$size.'"] li')->each(static fn (Crawler $li): string => trim($li->text())));
        self::assertSame('s42', $crawler->filter('[data-axis="'.$size.'"] .selected')->text());
        self::assertCount(1, $crawler->filter('.stock-in_stock'));

        // клік по «s38» веде на варіант 38 того ж кольору
        $link = (string) $crawler->filter('[data-axis="'.$size.'"] a')->attr('href');
        $target = [$size => 's38', $color => 'red'];
        ksort($target); // осі в посиланні — у стабільному (алфавітному) порядку
        self::assertSame($this->productUrl($p1, $locale, $target), $link);
        $crawler = $this->get($link);
        self::assertResponseIsSuccessful();
        self::assertSame((string) $this->fx->v['V2']->getId(), $crawler->filter('.price')->attr('data-variant-id'));
        self::assertStringContainsString(Money::of(1200, 'UAH')->format($locale), $crawler->filter('.price')->text());
        self::assertSame($this->fx->v['V2']->getSku(), $crawler->filter('.sku')->text());
        // варіант обрано осями — форма «в кошик» не дублює його select-ом, кладе саме V2; липка панель сабмітить ту саму форму (UX-аудит S6, S8)
        $form = $crawler->filter('form.add-to-cart');
        self::assertCount(0, $form->filter('select[name="variant"]'));
        self::assertSame((string) $this->fx->v['V2']->getId(), $form->filter('input[type="hidden"][name="variant"]')->attr('value'));
        self::assertSame($form->attr('id'), $crawler->filter('.buy-bar button[type="submit"]')->attr('form'));
        self::assertStringContainsString(Money::of(1200, 'UAH')->format($locale), $crawler->filter('.buy-bar-price')->text());
        self::assertSame($this->productUrl($p1, $locale, [], UrlGeneratorInterface::ABSOLUTE_URL), $crawler->filter('link[rel="canonical"]')->attr('href'), 'варіант не дублює сторінку');

        // неіснуюче значення осі — не 404, а найближчий варіант
        $this->get($this->productUrl($p1, $locale, [$size => 'nosuch']));
        self::assertResponseIsSuccessful();
    }

    public function testUnavailableVariantsAreMarkedAndMadeToOrderPreferred(): void
    {
        $locale = self::defaultLocale();
        $p3 = $this->fx->p['P3'];
        $size = $this->fx->size->getCode();

        $crawler = $this->get($this->productUrl($p3, $locale));
        self::assertSame((string) $this->fx->v['V5']->getId(), $crawler->filter('.price')->attr('data-variant-id'), 'V4 без залишку — обрано V5 під замовлення');
        self::assertCount(1, $crawler->filter('.stock-to_order'));
        self::assertSame('s40', $crawler->filter('[data-axis="'.$size.'"] a.unavailable')->text());

        $crawler = $this->get($this->productUrl($p3, $locale, [$size => 's40']));
        self::assertSame((string) $this->fx->v['V4']->getId(), $crawler->filter('.price')->attr('data-variant-id'));
        self::assertCount(1, $crawler->filter('.stock-out_of_stock'));
    }

    public function testAttributesAndImages(): void
    {
        $locale = self::defaultLocale();
        $p1 = $this->fx->p['P1'];
        $media = new Media('p1-'.$this->suffix.'.jpg', 'image/jpeg', 1000);
        $this->em()->persist($media);
        $p1->addMedia($media);
        $this->em()->flush();

        $crawler = $this->get($this->productUrl($p1, $locale));
        $specs = [];
        $crawler->filter('.specs tr')->each(static function (Crawler $tr) use (&$specs): void {
            $specs[trim($tr->filter('th')->text())] = trim($tr->filter('td')->text());
        });
        self::assertSame('leather, mesh', $specs[$locale.':'.$this->fx->material->getCode()] ?? null);
        self::assertSame('0.8', $specs[$locale.':'.$this->fx->weight->getCode()] ?? null);
        self::assertArrayHasKey($locale.':'.$this->fx->waterproof->getCode(), $specs);

        $src = (string) $crawler->filter('.gallery img')->attr('src');
        self::assertStringContainsString('product_large', $src);
        self::assertStringContainsString('p1-'.$this->suffix.'.jpg', $src);
    }

    public function testViewItemEventDescribesSelectedVariant(): void
    {
        $settings = static::getContainer()->get(Settings::class);
        $settings->set(SettingKeys::TAGS_ON_DEV, '1');
        $settings->set(SettingKeys::GA4_MEASUREMENT_ID, 'G-ABC1234567');
        try {
            $locale = self::defaultLocale();
            $target = [$this->fx->size->getCode() => 's38', $this->fx->color->getCode() => 'red'];
            $crawler = $this->get($this->productUrl($this->fx->p['P1'], $locale, $target));
            $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame('view_item', $events[0]['event']);
            self::assertSame($this->fx->v['V2']->getSku(), $events[0]['ecommerce']['items'][0]['item_id'], 'подія — про обраний варіант, не про перший');
            self::assertEquals(12, $events[0]['ecommerce']['value']);
        } finally {
            $settings->invalidate();
        }
    }

    public function testNotFound(): void
    {
        $locale = self::defaultLocale();
        $this->get($this->productUrl($this->fx->p['P4'], $locale));
        self::assertResponseStatusCodeSame(404, 'вимкнений товар');

        $this->get($this->url('site_product', ['slug' => 'no-such-product-'.$this->suffix, '_locale' => $locale]));
        self::assertResponseStatusCodeSame(404);

        $locales = self::locales();
        if (\count($locales) > 1) {
            $this->get($this->url('site_product', ['slug' => (string) $this->fx->p['P1']->getTranslation($locales[1])?->getSlug(), '_locale' => $locales[0]]));
            self::assertResponseStatusCodeSame(404, 'слаг іншої мови');
        }
    }

    /** Опис із фіду постачальника на сторінці: шаблон виводить |raw, тож у БД має лежати лише білий список (консиліум безпеки 10.10 п. 2). */
    public function testImportedSupplierDescriptionRendersWithoutScripts(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('catalog:import:feed'));
        $tester->execute(['feed' => __DIR__.'/../../Fixtures/feeds/xss.yml', '--source' => 'xss_site', '--sync' => true, '--locale' => self::defaultLocale()]);
        $tester->assertCommandIsSuccessful();
        $this->em()->clear();
        $product = $this->em()->createQuery('SELECT p FROM '.Product::class.' p JOIN p.variants v WHERE v.sku = :sku')->setParameter('sku', 'XSS-1')->getOneOrNullResult();
        self::assertInstanceOf(Product::class, $product);

        $crawler = $this->get($this->productUrl($product, self::defaultLocale()));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString("alert('xss')", $html);
        self::assertStringNotContainsString('onerror=', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertSame('МДФ', $crawler->filter('section.prose strong')->text());
        self::assertSame('https://example.com/doc', $crawler->filter('section.prose a[target="_blank"]')->attr('href'));
    }
}
