<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Entity\Product;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use App\Site\Controller\SitemapController;
use App\Site\Seo\CatalogSitemap;
use App\Site\SiteUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use DOMDocument;
use DOMXPath;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** SEO каталогу (seo-tech-notes п. 1, 4, 17, 18, 21, 24): schema Product/Offer, og/twitter, ItemList, dataLayer, sitemap. */
final class SeoCatalogTest extends CatalogPageTestCase
{
    protected function tearDown(): void
    {
        static::getContainer()->get(Settings::class)->invalidate();
        parent::tearDown();
    }

    public function testProductSchemaOffersAndOgLocale(): void
    {
        $locale = self::defaultLocale();
        $crawler = $this->get($this->productUrl($this->fx->p['P1'], $locale));
        self::assertResponseIsSuccessful();
        $product = self::ld($crawler, 'Product');
        self::assertNotNull($product);
        self::assertSame($locale.' P1', $product['name']);
        self::assertSame(['@type' => 'Brand', 'name' => 'Nike '.$this->suffix], $product['brand']);
        self::assertSame($this->productUrl($this->fx->p['P1'], $locale, [], UrlGeneratorInterface::ABSOLUTE_URL), $product['url']);
        // P1: V1 10.00 і V2 12.00 — AggregateOffer; в наявності
        self::assertSame(['@type' => 'AggregateOffer', 'lowPrice' => '10.00', 'highPrice' => '12.00', 'offerCount' => 2, 'priceCurrency' => 'UAH', 'availability' => 'https://schema.org/InStock'], array_intersect_key($product['offers'], array_flip(['@type', 'lowPrice', 'highPrice', 'offerCount', 'priceCurrency', 'availability'])));
        self::assertArrayNotHasKey('aggregateRating', $product, 'відгуків нема');

        self::assertSame(str_replace('-', '_', self::hreflangCode($locale)), $crawler->filter('meta[property="og:locale"]')->attr('content'));
        $alternates = $crawler->filter('meta[property="og:locale:alternate"]')->each(static fn (Crawler $m): string => (string) $m->attr('content'));
        self::assertCount(\count(self::locales()) - 1, $alternates, 'інші мови, де товар є');
        if (\in_array('en', self::locales(), true) && 'en' !== $locale) {
            self::assertContains('en_US', $alternates, 'hreflang «en» без регіону → og en_US, не «en»');
        }
        self::assertSame('summary', $crawler->filter('meta[name="twitter:card"]')->attr('content'), 'без фото — summary');

        $single = $this->get($this->productUrl($this->fx->p['P6'], $locale));
        self::assertSame(['@type' => 'Offer', 'price' => '30.00'], array_intersect_key(self::ld($single, 'Product')['offers'] ?? [], array_flip(['@type', 'price'])), 'один варіант — Offer');
    }

    /** «Ціна від» (PriceMode::From): AggregateOffer з lowPrice навіть для одного варіанта — точна Offer.price неправдива. */
    public function testPriceFromIsAggregateOfferWithLowPrice(): void
    {
        $this->fx->p['P6']->setPriceMode(\App\Core\Catalog\Enum\PriceMode::From);
        $this->fx->p['P1']->setPriceMode(\App\Core\Catalog\Enum\PriceMode::From);
        $this->em()->flush();
        $locale = self::defaultLocale();

        $single = self::ld($this->get($this->productUrl($this->fx->p['P6'], $locale)), 'Product')['offers'] ?? [];
        self::assertSame('AggregateOffer', $single['@type'] ?? null);
        self::assertSame('30.00', $single['lowPrice'] ?? null);
        self::assertArrayNotHasKey('price', $single);
        self::assertArrayNotHasKey('highPrice', $single, 'один варіант — лише нижня межа');

        $multi = self::ld($this->get($this->productUrl($this->fx->p['P1'], $locale)), 'Product')['offers'] ?? [];
        self::assertSame(['AggregateOffer', '10.00', '12.00'], [$multi['@type'] ?? null, $multi['lowPrice'] ?? null, $multi['highPrice'] ?? null]);
    }

    public function testPriceOnRequestHasNoOfferAndNoZeroPriceInDataLayer(): void
    {
        static::getContainer()->get(Settings::class)->set(SettingKeys::TAGS_ON_DEV, '1');
        $crawler = $this->get($this->productUrl($this->fx->p['P5'], self::defaultLocale()));
        self::assertResponseIsSuccessful();
        $product = self::ld($crawler, 'Product');
        self::assertNotNull($product);
        self::assertArrayNotHasKey('offers', $product, 'ціна за запитом — без Offer (інакше Google покаже 0 грн)');

        $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 512, \JSON_THROW_ON_ERROR);
        $viewItem = array_values(array_filter($events, static fn (array $e): bool => 'view_item' === ($e['event'] ?? null)))[0] ?? null;
        self::assertNotNull($viewItem);
        self::assertArrayNotHasKey('value', $viewItem['ecommerce']);
        self::assertArrayNotHasKey('price', $viewItem['ecommerce']['items'][0]);
    }

    public function testCategoryItemListAndOgImage(): void
    {
        $locale = self::defaultLocale();
        $crawler = $this->get($this->categoryUrl($this->fx->sneakers, $locale));
        $list = self::ld($crawler, 'ItemList');
        self::assertNotNull($list);
        self::assertSame(
            array_map(fn (string $n): string => $this->productUrl($this->fx->p[$n], $locale, [], UrlGeneratorInterface::ABSOLUTE_URL), ['P3', 'P1', 'P2']),
            array_column($list['itemListElement'], 'url'),
            'товари сторінки в її порядку',
        );
        self::assertSame([1, 2, 3], array_column($list['itemListElement'], 'position'));
        self::assertCount(0, $crawler->filter('meta[property="og:image"]'), 'без фото й зображення плитки — без og:image');
    }

    public function testSitemapListsCatalogWithHreflangOnlyForExistingTranslations(): void
    {
        $locales = self::locales();
        $onlyDefault = new Product('SM-'.$this->suffix, 'UAH');
        $onlyDefault->translate(self::defaultLocale())->setName('Лише мовою магазину')->setSlug('sm-'.$this->suffix);
        $onlyDefault->getDefaultVariant()->setPrice(Money::of(100, 'UAH'));
        $onlyDefault->addCategory($this->fx->bags);
        $this->em()->persist($onlyDefault);
        $this->em()->flush();

        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        $xml = (string) $this->client->getResponse()->getContent();
        $running = $this->categoryUrl($this->fx->running, $locales[0], [], UrlGeneratorInterface::ABSOLUTE_URL);
        self::assertStringContainsString('<loc>'.$running.'</loc>', $xml, 'вкладена категорія');
        foreach ($locales as $locale) {
            self::assertStringContainsString('<loc>'.$this->productUrl($this->fx->p['P1'], $locale, [], UrlGeneratorInterface::ABSOLUTE_URL).'</loc>', $xml);
        }
        self::assertStringNotContainsString($this->productUrl($this->fx->p['P4'], $locales[0], [], UrlGeneratorInterface::ABSOLUTE_URL), $xml, 'вимкнений товар — ні');

        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xpath->registerNamespace('x', 'http://www.w3.org/1999/xhtml');
        $url = $this->productUrl($onlyDefault, self::defaultLocale(), [], UrlGeneratorInterface::ABSOLUTE_URL);
        $entries = $xpath->query('//s:url[s:loc="'.$url.'"]');
        $links = $xpath->query('//s:url[s:loc="'.$url.'"]/x:link');
        self::assertNotFalse($entries);
        self::assertNotFalse($links);
        self::assertSame(1, $entries->length, 'товар лише однією мовою — один URL');
        self::assertSame(1, $links->length, 'і hreflang лише на себе');
    }

    public function testSitemapIndexForLargeCatalog(): void
    {
        $c = static::getContainer();
        // поріг 20 адрес замість 45 000: головний файл + файли товарів
        $controller = new SitemapController($c->get(EntityManagerInterface::class), $c->get(SiteUrlGenerator::class), $c->get(CatalogSitemap::class), 20);
        $controller->setContainer($c);
        $index = (string) $controller()->getContent();
        self::assertStringContainsString('<sitemapindex', $index);
        self::assertStringContainsString('/sitemap-main.xml</loc>', $index);
        self::assertStringContainsString('/sitemap-products-1.xml</loc>', $index);

        $perFile = intdiv(20, \count(self::locales()));
        $first = (string) $controller->products(1)->getContent();
        $urls = substr_count($first, '<url>');
        self::assertGreaterThan(0, $urls);
        self::assertLessThanOrEqual($perFile * \count(self::locales()), $urls, 'у файлі товарів — не більше порогу адрес');
        // файли не перетинаються: межі — за id (keyset), кожен товар рівно в одному файлі
        $plan = $c->get(CatalogSitemap::class)->plan($perFile);
        $seen = [];
        foreach (array_keys($plan['starts']) as $i) {
            preg_match_all('~<loc>([^<]+)</loc>~', (string) $controller->products($i + 1)->getContent(), $m);
            foreach ($m[1] as $loc) {
                self::assertArrayNotHasKey($loc, $seen, 'адреса в двох файлах: '.$loc);
                $seen[$loc] = true;
            }
        }
        self::assertSame($plan['count'], (int) $c->get(\Doctrine\DBAL\Connection::class)->fetchOne('SELECT COUNT(*) FROM catalog_product WHERE active = 1'));
        $main = (string) $controller->main()->getContent();
        self::assertStringContainsString($this->categoryUrl($this->fx->running, self::defaultLocale(), [], UrlGeneratorInterface::ABSOLUTE_URL), $main, 'категорії — у головному файлі');
        $this->expectException(NotFoundHttpException::class);
        $controller->products(100000);
    }

    /** Файли товарів через HTTP: /sitemap-products-1.xml — товари (keyset за id), за межею плану — 404. */
    public function testProductSitemapFilesOverHttp(): void
    {
        $this->client->request('GET', '/sitemap-products-1.xml');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
        $xml = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<loc>'.$this->productUrl($this->fx->p['P6'], self::defaultLocale(), [], UrlGeneratorInterface::ABSOLUTE_URL).'</loc>', $xml);
        self::assertStringNotContainsString($this->productUrl($this->fx->p['P4'], self::defaultLocale(), [], UrlGeneratorInterface::ABSOLUTE_URL), $xml, 'вимкнений — ні');
        self::assertStringNotContainsString($this->categoryUrl($this->fx->running, self::defaultLocale(), [], UrlGeneratorInterface::ABSOLUTE_URL), $xml, 'категорії — лише в головному файлі');

        $this->client->request('GET', '/sitemap-products-2.xml');
        self::assertResponseStatusCodeSame(404, 'весь тестовий каталог — в одному файлі');
    }

    /** @return array<string, mixed>|null перший JSON-LD заданого @var */
    private static function ld(Crawler $crawler, string $type): ?array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]')->each(static fn (Crawler $s): string => $s->text()) as $json) {
            $data = json_decode($json, true);
            if (\is_array($data) && $type === ($data['@type'] ?? null)) {
                return $data;
            }
        }

        return null;
    }
}
