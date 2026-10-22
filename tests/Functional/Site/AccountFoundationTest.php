<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * E12 PR-0 «фундамент»: firewall site (без host, після admin) з провайдером покупців, /account лише покупцю; форми
 * картки товару — stateless CSRF (перевірка за Origin / Sec-Fetch-Site, без JS і без сесії), тож сторінка товару
 * сесію не стартує (консиліум 07.10, пп. 1, 8).
 */
final class AccountFoundationTest extends CatalogPageTestCase
{
    public function testProductPageDoesNotStartSession(): void
    {
        $crawler = $this->get($this->productUrl($this->fx->p['P1'], self::defaultLocale()));
        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame([], array_map(static fn ($c): string => $c->getName(), $response->headers->getCookies()), 'жодної cookie, зокрема сесійної');
        self::assertSessionNotStarted($response);
        self::assertGreaterThan(0, $crawler->filter('form.add-to-cart')->count());
        foreach ($crawler->filter('form input[name="_token"]')->each(static fn (Crawler $n): ?string => $n->attr('value')) as $value) {
            self::assertSame('csrf-token', $value, 'stateless-маркер, не секрет із сесії');
        }
        $tokens = static::getContainer()->get(CsrfTokenManagerInterface::class);
        foreach (['cart', 'quick_order', 'price_request'] as $id) {
            self::assertSame('csrf-token', $tokens->getToken($id)->getValue(), $id.' — stateless');
        }
    }

    public function testCartPageWithoutCookieDoesNotStartSession(): void
    {
        $this->client->request('GET', 'http://localhost/cart/');
        self::assertResponseIsSuccessful();
        self::assertSessionNotStarted($this->client->getResponse());
    }

    public function testFlashesStillShownAfterRedirectWithSession(): void
    {
        $crawler = $this->get($this->productUrl($this->fx->p['P1'], self::defaultLocale()));
        $variant = (string) $crawler->filter('form.add-to-cart input[name="variant"], form.add-to-cart select[name="variant"] option')->first()->attr('value');
        $this->client->request('POST', 'http://localhost/cart/add', ['variant' => $variant, 'quantity' => '1', '_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('.flash-success'), 'cookie сесії є — флеш показано');
    }

    public function testCartBackIgnoresLookalikeHost(): void
    {
        $crawler = $this->get($this->productUrl($this->fx->p['P1'], self::defaultLocale()));
        $variant = (string) $crawler->filter('form.add-to-cart input[name="variant"], form.add-to-cart select[name="variant"] option')->first()->attr('value');
        $this->client->restart();
        // помилка кількості → назад за Referer; http://localhost.evil.example починається з http://localhost
        $this->client->request('POST', 'http://localhost/cart/add', ['variant' => $variant, 'quantity' => '-1', '_token' => 'csrf-token'], server: [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_REFERER' => 'http://localhost.evil.example/phish',
        ]);
        self::assertResponseRedirects('/cart/');
    }

    public function testCartAndQuickOrderWorkWithoutJsBySameOrigin(): void
    {
        $product = $this->productUrl($this->fx->p['P1'], self::defaultLocale());
        $crawler = $this->get($product);
        $variant = (string) $crawler->filter('form.add-to-cart input[name="variant"], form.add-to-cart select[name="variant"] option')->first()->attr('value');
        $cart = ['variant' => $variant, 'quantity' => '1', '_token' => 'csrf-token'];

        // звичайна HTML-форма браузера: Origin свого сайту, без JS-заголовків і cookie подвійного сабміту
        $this->client->restart();
        $this->client->request('POST', 'http://localhost/cart/add', $cart, server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects();
        $this->client->restart();
        $this->client->request('POST', 'http://localhost/cart/add', $cart, server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects(null, null, 'сучасні браузери: Sec-Fetch-Site');

        // чужий сайт або запит без Origin/Referer — відмова
        $this->client->restart();
        $this->client->request('POST', 'http://localhost/cart/add', $cart, server: ['HTTP_ORIGIN' => 'https://evil.example']);
        self::assertResponseStatusCodeSame(403);
        $this->client->restart();
        $this->client->request('POST', 'http://localhost/cart/add', $cart, server: ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_REFERER' => 'http://localhost/']);
        self::assertResponseStatusCodeSame(403, 'Sec-Fetch-Site важить більше за Referer');
        $this->client->restart();
        $this->client->request('POST', 'http://localhost/cart/add', $cart, server: ['HTTP_SEC_FETCH_SITE' => 'same-site']);
        self::assertResponseStatusCodeSame(403, 'same-site (сусідній піддомен) — не свій origin');
        $this->client->restart();
        $this->client->request('POST', 'http://localhost/cart/add', $cart);
        self::assertResponseStatusCodeSame(403, 'без Origin і Referer');

        $order = ['variant' => $variant, 'quantity' => '1', 'name' => 'Іван', 'phone' => '+380501112233', '_token' => 'csrf-token'];
        $this->client->restart();
        $this->client->request('POST', 'http://localhost/quick-order', $order, server: ['HTTP_ORIGIN' => 'https://evil.example']);
        self::assertResponseStatusCodeSame(403);
        $this->client->restart();
        $this->client->request('POST', 'http://localhost/quick-order', $order, server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects();
    }

    public function testSiteFirewallIsCatchAllAfterAdminAndAccountNeedsCustomer(): void
    {
        $c = static::getContainer();
        /** @var string $siteHost */
        $siteHost = $c->getParameter('site_host');
        /** @var string $adminHost */
        $adminHost = $c->getParameter('admin_host');
        $map = $c->get('security.firewall.map');
        foreach (['https://'.$siteHost.'/', 'https://www.klient-shop.com.ua/ru/tovar', 'https://shop-engine-b.localhost/'] as $url) {
            $config = $map->getFirewallConfig(Request::create($url));
            self::assertSame('site', $config?->getName(), $url);
            self::assertSame('site', $config->getContext());
            self::assertSame('security.user.provider.concrete.customers', $config->getProvider(), 'адмінські облікові записи на сайті не входять');
            self::assertContains('remember_me', $config->getAuthenticators());
        }
        self::assertSame('admin', $map->getFirewallConfig(Request::create('https://'.$adminHost.'/'))?->getName());

        $access = $c->get('security.access_map');
        $roles = static fn (string $url): ?array => $access->getPatterns(Request::create($url))[0];
        self::assertSame(['ROLE_CUSTOMER'], $roles('https://'.$siteHost.'/account'));
        self::assertSame(['ROLE_CUSTOMER'], $roles('https://'.$siteHost.'/account/orders'));
        self::assertSame(['ROLE_CUSTOMER'], $roles('https://'.$siteHost.'/ru/account/'));
        self::assertNull($roles('https://'.$siteHost.'/accounting-books'), 'товар зі слагом, що починається на account');
        self::assertNull($roles('https://'.$siteHost.'/'));
        self::assertSame(['ROLE_MANAGER'], $roles('https://'.$adminHost.'/account'), 'на хості адмінки — правила адмінки');
    }

    /** AbstractSessionListener ставить «private, max-age=0, must-revalidate», щойно сесію використано (зокрема app.flashes()). */
    private static function assertSessionNotStarted(\Symfony\Component\HttpFoundation\Response $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringNotContainsString('max-age=0', $cacheControl, 'сесію не стартовано');
        self::assertStringNotContainsString('must-revalidate', $cacheControl, 'сесію не стартовано');
        self::assertFalse($response->headers->has(\Symfony\Component\HttpKernel\EventListener\AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER));
    }
}
