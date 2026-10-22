<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Analytics\ConsentState;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\FilterIndex\FilterIndexer;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/** Теги за Settings: з увімкненими id і «теги на dev» — сніпети на місці; вимкнено — нічого; consent за мовою; dataLayer з рендеру. */
final class AnalyticsTagsTest extends WebTestCase
{
    private KernelBrowser $client;
    private Settings $settings;
    private string $host;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->settings = static::getContainer()->get(Settings::class);
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->host = 'https://'.$host;
        $this->settings->invalidate();
    }

    protected function tearDown(): void
    {
        $this->settings->invalidate();
        parent::tearDown();
    }

    /** Консиліум PR #6 (low): читання черги dataLayer не стартує сесію — анонімна сторінка без session-cookie. */
    public function testPageViewDoesNotStartSession(): void
    {
        $this->enableAll();
        $crawler = $this->client->request('GET', $this->host.'/');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('script[data-analytics-events]'), 'Провайдер подій відпрацював');
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'Жодного cookie (зокрема сесії) анонімному відвідувачу');
    }

    public function testSnippetsPresentWhenEnabled(): void
    {
        $this->enableAll();
        $html = (string) $this->client->request('GET', $this->host.'/')->html();
        self::assertResponseIsSuccessful();

        self::assertStringContainsString("googletagmanager.com/gtm.js?id='+i+dl", $html, 'Офіційний сніпет GTM');
        self::assertStringContainsString("'dataLayer','GTM-TEST123'", $html);
        self::assertStringContainsString('googletagmanager.com/ns.html?id=GTM-TEST123', $html, 'noscript-iframe у body');
        self::assertStringNotContainsString('gtag/js?id=G-', $html, 'GA4 через gtag не виводиться, коли є GTM');
        self::assertStringContainsString("fbq('init','123456789012')", $html);
        self::assertStringContainsString('<meta name="google-site-verification" content="gsc-token_ABC123">', $html);
        self::assertStringContainsString('<meta name="msvalidate.01" content="BING1234567890">', $html);
        self::assertStringContainsString('<script data-custom="head">1</script>', $html, 'Код у head сирим');
        self::assertStringContainsString('<div data-custom="start"></div>', $html);
        self::assertStringContainsString('<script data-custom="end">2</script>', $html);
        self::assertStringContainsString('gtm.js', $html);
        self::assertStringNotContainsString('mousemove', $html, 'Без lazy-load по взаємодії');
        self::assertLessThan(strpos($html, 'gtm.js?id'), strpos($html, "gtag('consent','default'"), 'consent default стоїть перед GTM');

        // порядок у body: noscript GTM одразу після <body>, події dataLayer перед </body>
        self::assertLessThan(strpos($html, '<header'), strpos($html, 'ns.html?id=GTM-TEST123'));
        self::assertStringContainsString('data-analytics-events', $html);
    }

    public function testGa4WithoutGtm(): void
    {
        $this->enableAll();
        $this->settings->set(SettingKeys::GTM_CONTAINER_ID, null);
        $html = (string) $this->client->request('GET', $this->host.'/')->html();
        self::assertStringContainsString('googletagmanager.com/gtag/js?id=G-ABC1234567', $html);
        self::assertStringContainsString("gtag('config','G-ABC1234567')", $html);
        self::assertStringNotContainsString('gtm.js', $html);
    }

    public function testInvalidIdsAreNotRenderedAndFlaggedInAdminList(): void
    {
        $this->enableAll();
        $this->settings->set(SettingKeys::GTM_CONTAINER_ID, '<script>alert(1)</script>');
        $this->settings->set(SettingKeys::GA4_MEASUREMENT_ID, 'UA-12345-1');
        $html = (string) $this->client->request('GET', $this->host.'/')->html();
        self::assertStringNotContainsString('alert(1)', $html, 'Код замість id не виводиться');
        self::assertStringNotContainsString('gtm.js', $html);
        self::assertStringNotContainsString('UA-12345-1', $html, 'Старий формат UA не виводиться');
        self::assertFalse(SettingKeys::isValidValue(SettingKeys::GTM_CONTAINER_ID, '<script>'));
        self::assertTrue(SettingKeys::isValidValue(SettingKeys::GTM_CONTAINER_ID, 'gtm-abc1234'));
        self::assertSame('GTM-ABC1234', SettingKeys::validValue(SettingKeys::GTM_CONTAINER_ID, ' gtm-abc1234 '));
    }

    public function testNothingWhenTagsDisabledOnDev(): void
    {
        $this->enableAll();
        $this->settings->set(SettingKeys::TAGS_ON_DEV, '0');
        $html = (string) $this->client->request('GET', $this->host.'/')->html();
        self::assertResponseIsSuccessful();
        foreach (['googletagmanager', 'fbq(', 'google-site-verification', 'msvalidate.01', 'data-custom', "gtag('consent'", 'data-analytics-events'] as $needle) {
            self::assertStringNotContainsString($needle, $html, $needle);
        }
    }

    public function testConsentDefaultByLocaleAndCookie(): void
    {
        $this->enableAll();
        $this->settings->set(SettingKeys::CONSENT_GRANTED_LOCALES, 'uk,ru');

        $html = (string) $this->client->request('GET', $this->host.'/')->html();
        self::assertStringContainsString('"analytics_storage":"granted"', $html, 'uk — згода за замовчуванням');
        self::assertStringNotContainsString('id="consent-banner"', $html);

        $html = (string) $this->client->request('GET', $this->host.'/en/')->html();
        self::assertStringContainsString('"analytics_storage":"denied"', $html, 'en — denied + банер');
        self::assertStringContainsString('"ad_storage":"denied"', $html);
        self::assertStringContainsString('id="consent-banner"', $html);
        self::assertStringContainsString('Essential only', $html, 'Тексти банера мовою сторінки');

        $this->client->getCookieJar()->set(new Cookie(ConsentState::COOKIE, 'granted', null, '/', parse_url($this->host, \PHP_URL_HOST) ?: ''));
        $html = (string) $this->client->request('GET', $this->host.'/en/')->html();
        self::assertStringContainsString('"analytics_storage":"granted"', $html, 'Cookie користувача перекриває дефолт');
        self::assertStringNotContainsString('id="consent-banner"', $html);
    }

    public function testServerRenderedEcommerceEvents(): void
    {
        $this->enableAll();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $category = new Category();
        $category->translate('uk')->setName('Двері')->setSlug('dveri-an');
        $product = new Product('AN-1', 'UAH');
        $product->translate('uk')->setName('Двері AN')->setSlug('dveri-an-1');
        $product->getDefaultVariant()->setPrice(Money::of(123_450, 'UAH'));
        $product->addCategory($category);
        $em->persist($category);
        $em->persist($product);
        $em->flush();
        // лістинг категорії читає read-index фільтрів (у test Messenger — in-memory, тож індексуємо напряму)
        static::getContainer()->get(FilterIndexer::class)->reindexProducts([(int) $product->getId()]);

        $crawler = $this->client->request('GET', $this->host.'/dveri-an-1');
        self::assertResponseIsSuccessful();
        $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('view_item', $events[0]['event']);
        self::assertSame(['item_id' => 'AN-1', 'item_name' => 'Двері AN', 'price' => 1234.5, 'quantity' => 1, 'currency' => 'UAH', 'item_category' => 'Двері'], $events[0]['ecommerce']['items'][0]);
        self::assertSame(1234.5, $events[0]['ecommerce']['value']);

        $crawler = $this->client->request('GET', $this->host.'/dveri-an/');
        $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('view_item_list', $events[0]['event']);
        self::assertSame('Двері', $events[0]['ecommerce']['item_list_name']);
        self::assertSame('AN-1', $events[0]['ecommerce']['items'][0]['item_id']);
        $select = json_decode((string) $crawler->filter('.cards a[data-analytics-select]')->first()->attr('data-analytics-select'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('select_item', $select['event']);
    }

    private function enableAll(): void
    {
        $this->settings->set(SettingKeys::TAGS_ON_DEV, '1');
        $this->settings->set(SettingKeys::GTM_CONTAINER_ID, 'GTM-TEST123');
        $this->settings->set(SettingKeys::GA4_MEASUREMENT_ID, 'G-ABC1234567');
        $this->settings->set(SettingKeys::FB_PIXEL_ID, '123456789012');
        $this->settings->set(SettingKeys::GOOGLE_SITE_VERIFICATION, 'gsc-token_ABC123');
        $this->settings->set(SettingKeys::BING_SITE_VERIFICATION, 'BING1234567890');
        $this->settings->set(SettingKeys::HTML_HEAD, '<script data-custom="head">1</script>');
        $this->settings->set(SettingKeys::HTML_BODY_START, '<div data-custom="start"></div>');
        $this->settings->set(SettingKeys::HTML_BODY_END, '<script data-custom="end">2</script>');
    }
}
