<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\Post;
use App\Core\Content\Entity\PostCategory;
use App\Core\Order\Entity\Order;
use DateTimeImmutable;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * Рендер-тест теми паку (E14). У test app.store_templates_dir вказує на фікстурну тему tests/store/pack-demo/themes/demo/templates
 * (заготовки extends '@engine/…' з bin/store theme new + посилання в header_actions і банер на головній), тож StoreTemplatesPass
 * ставить її першою в головному namespace — як templates/store/ у зібраному образі. Усі публічні маршрути сайту × мови зі
 * strict_variables: 2xx (або очікуваний 3xx/410), перекриття застосовано на кожній сторінці, чутливі зони (лого, canonical,
 * перемикач UA/RU/EN, <main>) на місці; кошик і checkout — з товаром, «дякуємо» — із замовленням у сесії, «прийнято» — 410.
 * Без перекриття (@engine напряму) база рендериться так само, лише без доданого посилання — «classic» = нинішній вигляд.
 */
final class PackRenderTest extends CatalogPageTestCase
{
    public function testEveryPublicRouteRendersWithPackTheme(): void
    {
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        // stateless CSRF форм — Origin свого сайту; свій IP — ліміти замовлень не накопичуються між тестами
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://'.$host);
        $this->client->setServerParameter('REMOTE_ADDR', '203.0.113.'.random_int(1, 254));
        $default = self::defaultLocale();

        $page = new Page();
        $page->setPublished(true);
        $news = new PostCategory('news_'.$this->suffix);
        $post = new Post();
        $post->setCategory($news);
        $post->setPublishedAt(new DateTimeImmutable('-1 day'));
        foreach (self::locales() as $locale) {
            $page->translate($locale)->setTitle($locale.' page '.$this->suffix)->setBody('<p>'.$locale.'</p>');
            $news->translate($locale)->setName($locale.' news');
            $post->translate($locale)->setTitle($locale.' post '.$this->suffix)->setExcerpt('x')->setBody('<p>'.$locale.'</p>');
        }
        // P6 — один варіант з ціною: у кошик і швидке замовлення без вибору осей
        $product = $this->fx->p['P6'];
        $product->getDefaultVariant()->setStock('10');
        $this->em()->persist($page);
        $this->em()->persist($news);
        $this->em()->persist($post);
        $this->em()->flush();

        // кошик з товаром (cookie живе в клієнті на всі мови) → checkout рендериться, а не веде в порожній кошик
        $crawler = $this->get($this->productUrl($product, $default));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form.add-to-cart')->form());
        self::assertResponseRedirects();
        // швидке замовлення → посилання з токеном → замовлення в сесії → «дякуємо» без токена рендериться
        $crawler = $this->get($this->productUrl($product, $default));
        $this->client->submit($crawler->filter('form.quick-order')->form(['name' => 'Тест теми', 'phone' => '0671234590', 'quantity' => '1']));
        self::assertResponseRedirects();
        $tokenUrl = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/order/[a-f0-9]{48}/thanks$#', $tokenUrl);
        $this->client->request('GET', $tokenUrl);
        self::assertResponseRedirects();

        $rendered = 0;
        foreach (self::locales() as $locale) {
            $urls = [
                'site_home' => $this->url('site_home', ['_locale' => $locale]),
                'site_category' => $this->categoryUrl($this->fx->shoes, $locale),
                'site_product' => $this->productUrl($this->fx->p['P1'], $locale),
                'site_page' => $this->url('site_page', ['slug' => (string) $page->getTranslation($locale)?->getSlug(), '_locale' => $locale]),
                'site_blog' => $this->url('site_blog', ['_locale' => $locale]),
                'site_search' => $this->url('site_search', ['q' => 'P1', '_locale' => $locale]),
                'site_post' => $this->url('site_post', ['slug' => (string) $post->getTranslation($locale)?->getSlug(), '_locale' => $locale]),
                'site_cart' => $this->url('site_cart', ['_locale' => $locale]),
                'site_checkout' => $this->url('site_checkout', ['_locale' => $locale]),
                'site_order_thanks_page' => $this->url('site_order_thanks_page', ['_locale' => $locale]),
                'site_account_login' => $this->url('site_account_login', ['_locale' => $locale]),
                'site_account_register' => $this->url('site_account_register', ['_locale' => $locale]),
                'site_account_password' => $this->url('site_account_password', ['_locale' => $locale]),
                'site_account_login_link' => $this->url('site_account_login_link', ['_locale' => $locale]),
            ];
            foreach ($urls as $route => $url) {
                $crawler = $this->get($url);
                $label = $route.' '.$locale.' '.$url;
                self::assertResponseIsSuccessful($label.': сторінка з темою паку має рендеритись, не редиректити');
                ++$rendered;
                $this->assertThemed($crawler, $label, !str_starts_with($route, 'site_account') && !str_starts_with($route, 'site_order'));
                if ('site_home' === $route) {
                    self::assertCount(1, $crawler->filter('#demo-pack.demo-pack-banner'), $label.': банер теми паку перед типовою головною');
                    self::assertCount(1, $crawler->filter('.hero h1'), $label.': типова головна (parent()) рендериться далі');
                }
                if ('site_checkout' === $route) {
                    self::assertCount(1, $crawler->filter('form.checkout'), $label.': checkout з товаром у кошику — форма, не редирект у порожній кошик');
                }
                if ('site_order_thanks_page' === $route) {
                    self::assertStringContainsString('0671234590', (string) $this->client->getResponse()->getContent(), $label.': «дякуємо» із замовленням сесії');
                }
            }
        }
        self::assertSame(14 * \count(self::locales()), $rendered);

        // прострочене посилання → «прийнято» (410) теж у темі паку
        $this->em()->clear();
        $order = $this->em()->getRepository(Order::class)->findOneBy(['phone' => '+380671234590']);
        self::assertInstanceOf(Order::class, $order);
        $this->em()->getConnection()->executeStatement('UPDATE sales_order SET placed_at = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE id = ?', [$order->getId()]);
        $crawler = $this->client->request('GET', $tokenUrl);
        self::assertResponseStatusCodeSame(410);
        $this->assertThemed($crawler, 'site_order_thanks (accepted) '.$tokenUrl, false);
    }

    public function testEngineBaseRendersIdenticallyWithoutOverride(): void
    {
        $container = static::getContainer();
        /** @var string $host */
        $host = $container->getParameter('site_host');
        $request = Request::create('https://'.$host.'/x');
        $request->setLocale(self::defaultLocale());
        $container->get(RequestStack::class)->push($request);
        $twig = $container->get(Environment::class);

        $body = '{% set alternates = {} %}{% block body %}content{% endblock %}';
        $themed = $twig->createTemplate("{% extends 'site/base.html.twig' %}".$body)->render();
        $engine = $twig->createTemplate("{% extends '@engine/site/base.html.twig' %}".$body)->render();

        self::assertStringContainsString('demo-pack-link', $themed, 'site/base.html.twig — заготовка паку поверх @engine (StoreTemplatesPass у test)');
        self::assertStringNotContainsString('demo-pack-link', $engine, '@engine — базовий шаблон рушія без перекриття');
        self::assertSame($engine, str_replace('<a class="header-link demo-pack-link" href="#demo-pack">Demo pack</a>', '', $themed), 'усі блоки заготовки = parent(): решта HTML ідентична рушію');
        // analytics_* у test вимкнено (порожній вивід), але каркас, лого й перемикач мов — на місці в обох
        foreach (['<main id="main">', 'brand-logo', 'class="lang"', '</html>'] as $zone) {
            self::assertStringContainsString($zone, $engine, $zone);
        }
    }

    /** Перекриття теми застосовано, чутливі зони на місці. */
    private function assertThemed(Crawler $crawler, string $label, bool $canonical): void
    {
        $html = (string) $this->client->getResponse()->getContent();
        self::assertCount(1, $crawler->filter('main#main'), $label.': каркас base');
        self::assertCount(1, $crawler->filter('header .demo-pack-link'), $label.': блок header_actions теми паку застосовано (extends @engine)');
        self::assertCount(1, $crawler->filter('header .brand-logo'), $label.': лого в шапці (правило власника)');
        self::assertCount(1, $crawler->filter('.lang .current'), $label.': перемикач мов');
        self::assertMatchesRegularExpression('#class="lang"[^>]*>.*?>UA</#s', $html, $label.': uk → UA текстом');
        self::assertStringContainsString('</html>', $html, $label);
        if ($canonical) {
            self::assertCount(1, $crawler->filter('link[rel="canonical"]'), $label.': canonical лишається');
        }
    }
}
