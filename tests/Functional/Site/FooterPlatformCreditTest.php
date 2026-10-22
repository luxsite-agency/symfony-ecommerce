<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Рядок платформи у футері за налаштуванням site.platform_credit (власник 19.10.2026): «Коротко» типово (рядок у БД порожній; «Ecommerce LuxSite» усіма мовами),
 * «Повний» з посиланням «Ліцензія», «Не показувати» — рядка нема; три мови. /license відкривається й noindex за будь-якого режиму.
 */
final class FooterPlatformCreditTest extends WebTestCase
{
    private const array SHORT = ['' => 'Ecommerce LuxSite', '/ru' => 'Ecommerce LuxSite', '/en' => 'Ecommerce LuxSite'];
    private const array FULL = [
        '' => ['Платформа Symfony ecommerce LuxSite · відкритий код під ліцензією Apache 2.0, розробники платформи: LuxSite · Ліцензія', '/license', 'Ліцензія'],
        '/ru' => ['Платформа Symfony ecommerce LuxSite · открытый код под лицензией Apache 2.0, разработчики платформы: LuxSite · Лицензия', '/ru/license', 'Лицензия'],
        '/en' => ['Platform Symfony ecommerce LuxSite · open source under the Apache License 2.0, platform developed by LuxSite · Licence', '/en/license', 'Licence'],
    ];

    private KernelBrowser $client;
    private string $host;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->host = 'https://'.$host;
    }

    protected function tearDown(): void
    {
        // кеш налаштувань переживає відкат транзакції DAMA — наступним тестам типове значення
        $this->settings()->set(SettingKeys::PLATFORM_CREDIT, null);
        parent::tearDown();
    }

    public function testShortIsDefaultWithoutLicenseLink(): void
    {
        self::assertSame('short', static::getContainer()->getParameter('app.settings_defaults')[SettingKeys::PLATFORM_CREDIT]);
        foreach (self::SHORT as $prefix => $text) {
            $line = $this->line($prefix.'/');
            self::assertCount(1, $line, $prefix);
            self::assertSame('short', $line->attr('data-platform-credit'));
            self::assertSame($text, trim($line->text()), $prefix);
            self::assertSame('https://luxsite.agency/', $line->filter('a')->attr('href'), 'powered_by увімкнено — назва з посиланням');
            self::assertSame('Ecommerce LuxSite', $line->filter('a')->text());
            self::assertCount(1, $line->filter('a'), 'лише назва платформи');
            self::assertCount(0, $this->client->getCrawler()->filter('a[href$="/license"]'), "$prefix: на /license з футера не посилаємось");
            self::assertStringNotContainsStringIgnoringCase('ліценз', $line->text());
            self::assertStringNotContainsStringIgnoringCase('licen', $line->text());
            self::assertDoesNotMatchRegularExpression('/Працює на|Работает на|Powered by|Symfony/u', $line->text(), 'без префікса й Symfony');
        }
        self::assertSame('Symfony ecommerce LuxSite', $this->client->getCrawler()->filter('meta[name="generator"]')->attr('content'), 'meta generator не залежить від режиму');
    }

    public function testFullShowsLicenseLineInEveryLocale(): void
    {
        $this->settings()->set(SettingKeys::PLATFORM_CREDIT, 'full');
        foreach (self::FULL as $prefix => [$text, $href, $link]) {
            $line = $this->line($prefix.'/');
            self::assertCount(1, $line, $prefix);
            self::assertSame('full', $line->attr('data-platform-credit'));
            self::assertStringContainsString($text, $line->text(), $prefix);
            self::assertSame($href, $line->filter('a.footer-license')->attr('href'));
            self::assertSame($link, $line->filter('a.footer-license')->text());
            self::assertSame('https://luxsite.agency/', $line->filter('a')->first()->attr('href'));
        }
    }

    public function testNoneHidesLineButLicensePageStaysNoindex(): void
    {
        $this->settings()->set(SettingKeys::PLATFORM_CREDIT, 'none');
        foreach (array_keys(self::SHORT) as $prefix) {
            self::assertCount(0, $this->line($prefix.'/'), $prefix);
            $footer = $this->client->getCrawler()->filter('.site-footer');
            self::assertCount(1, $footer, 'решта футера на місці');
            // назва тестового магазину збігається з назвою платформи, тож перевіряємо посилання й підписи рядка
            self::assertCount(0, $footer->filter('a[href="https://luxsite.agency/"]'));
            self::assertDoesNotMatchRegularExpression('/Працює на|Работает на|Powered by|Платформа|Platform/u', $footer->text());
            self::assertCount(0, $footer->filter('a[href$="/license"]'));
        }
        foreach (['none', 'short'] as $mode) {
            $this->settings()->set(SettingKeys::PLATFORM_CREDIT, $mode);
            $crawler = $this->client->request('GET', $this->host.'/license');
            self::assertResponseIsSuccessful("$mode: /license доступна за адресою");
            self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        }
    }

    public function testUnknownStoredValueFallsBackToShort(): void
    {
        $this->settings()->set(SettingKeys::PLATFORM_CREDIT, 'bogus');
        self::assertSame('short', $this->line('/')->attr('data-platform-credit'));
    }

    private function line(string $path): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', $this->host.$path);
        self::assertResponseIsSuccessful($path);

        return $crawler->filter('.site-footer .footer-powered');
    }

    private function settings(): Settings
    {
        return static::getContainer()->get(Settings::class);
    }
}
