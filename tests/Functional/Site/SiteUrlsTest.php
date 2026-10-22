<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

/**
 * Адреси без /c/ і /p/ (власник, 2026-09-28): категорія — /шлях/за/деревом/ зі слешем, товар — /{slug}, не-дефолтна
 * мова — з префіксом; статичні маршрути (і їхні локалізовані варіанти) раніше за catch-all; 301 нема.
 */
final class SiteUrlsTest extends CatalogPageTestCase
{
    public function testNestedCategoryAndRootProductUrlsInEveryLocale(): void
    {
        $s = $this->suffix;
        foreach (self::locales() as $locale) {
            $prefix = $locale === self::defaultLocale() ? '' : '/'.$locale;
            $shoes = $this->categorySlug($this->fx->shoes, $locale);
            $sneakers = $this->categorySlug($this->fx->sneakers, $locale);
            $running = $this->categorySlug($this->fx->running, $locale);

            self::assertSame("$prefix/$shoes/$sneakers/$running/", $this->categoryUrl($this->fx->running, $locale), 'генерація за тим самим іменем site_category');
            $crawler = $this->get("$prefix/$shoes/$sneakers/$running/");
            self::assertResponseIsSuccessful("$locale: вкладена категорія");
            self::assertStringContainsString($locale.' P1', $crawler->filter('main')->text(), "$locale: товар підкатегорії в лістингу");
            self::assertGreaterThan(0, $crawler->filter('a[href="'."$prefix/$shoes/$sneakers/".'"]')->count(), "$locale: хлібні крихти — за деревом");

            $product = $this->productUrl($this->fx->p['P1'], $locale);
            self::assertMatchesRegularExpression('#^'.preg_quote($prefix, '#').'/[a-z0-9-]+$#', $product, 'товар — від кореня, без категорії');
            $page = $this->get($product);
            self::assertResponseIsSuccessful("$locale: товар");
            self::assertSame($this->productUrl($this->fx->p['P1'], $locale, [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL), $page->filter('link[rel="canonical"]')->attr('href'));
            self::assertGreaterThan(0, $page->filter('a[href="'."$prefix/$shoes/$sneakers/$running/".'"]')->count(), "$locale: крихти товару — за головною категорією");
        }

        // слеш має значення: категорія без слеша і товар зі слешем — 404, без редиректів (301 не потрібні)
        $shoes = $this->categorySlug($this->fx->shoes, self::defaultLocale());
        $this->get('/'.$shoes);
        self::assertResponseStatusCodeSame(404);
        $productPath = $this->productUrl($this->fx->p['P1'], self::defaultLocale());
        $this->get($productPath.'/');
        self::assertResponseStatusCodeSame(404);

        // старих /c/ і /p/ нема, неіснуючі адреси — 404
        $this->get('/c/'.$shoes);
        self::assertResponseStatusCodeSame(404);
        $this->get('/p'.$productPath);
        self::assertResponseStatusCodeSame(404);
        $this->get("/no-such-$s");
        self::assertResponseStatusCodeSame(404);
        $this->get("/$shoes/no-such-$s/");
        self::assertResponseStatusCodeSame(404);
        $this->get('/Bad_Slug');
        self::assertResponseStatusCodeSame(404);
    }

    public function testStaticRoutesWinOverCatchAllInEveryLocale(): void
    {
        $router = static::getContainer()->get('router');
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $route = static function (string $path, string $method = 'GET') use ($router, $host): string {
            $router->getContext()->setHost($host)->setMethod($method);

            $match = $router->match($path);

            return $match['_route'].'.'.($match['_locale'] ?? '');
        };
        foreach (self::locales() as $locale) {
            $prefix = $locale === self::defaultLocale() ? '' : '/'.$locale;
            foreach ([
                '/cart/' => 'site_cart', '/cart.json' => 'site_cart_json', '/checkout/' => 'site_checkout', '/blog/' => 'site_blog',
                '/np/cities.json' => 'site_np_cities', '/np/warehouses.json' => 'site_np_warehouses', '/page/pro-nas' => 'site_page_legacy', '/pro-nas/' => 'site_catalog',
                '/blog/novyna' => 'site_post', '/order/'.str_repeat('a', 48).'/thanks' => 'site_order_thanks',
                '/odyah/futbolky/' => 'site_catalog', '/tovar-1' => 'site_catalog',
            ] as $path => $expected) {
                self::assertSame($expected.'.'.$locale, $route($prefix.$path), $locale.' '.$path);
            }
            self::assertSame('site_quick_order.'.$locale, $route($prefix.'/quick-order', 'POST'));
            self::assertSame('site_cart_add.'.$locale, $route($prefix.'/cart/add', 'POST'));
        }
        self::assertSame('site_sitemap.', $route('/sitemap.xml'));
        self::assertSame('site_price_request.', $route('/price-request', 'POST'));
        self::assertSame('site_lead_request.', $route('/request', 'POST'));
        self::assertSame('site_home.'.self::defaultLocale(), $route('/'));

        // HTTP: кошик і JSON кошика мовами — свої сторінки, не 404 каталогу
        foreach (self::locales() as $locale) {
            $prefix = $locale === self::defaultLocale() ? '' : '/'.$locale;
            $this->get("$prefix/cart/");
            self::assertResponseIsSuccessful("$locale: кошик");
            $this->get("$prefix/cart.json");
            self::assertResponseIsSuccessful("$locale: cart.json");
        }
    }
}
