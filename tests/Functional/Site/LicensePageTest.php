<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\License\ThirdPartyComponents;
use App\Site\Theme\Templates\StoreTemplates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Сторінка ліцензії платформи (site_license): кожною мовою 200 і noindex, текст uk/en (решта мов → en), власник без app.license =
 * назва магазину, перелік компонентів з composer.lock, без наших контактів; не в sitemap. Рядок у підвалі — FooterPlatformCreditTest.
 */
final class LicensePageTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $host;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->host = 'https://'.$host;
    }

    public function testLicensePageInEveryLocaleIsNoindexWithTextAndComponents(): void
    {
        /** @var list<string> $locales */
        $locales = static::getContainer()->getParameter('app.locales');
        /** @var string $default */
        $default = static::getContainer()->getParameter('app.default_locale');
        self::assertContains('en', $locales, 'Тестовий набір мов має містити en');
        self::assertContains('ru', $locales, 'Тестовий набір мов має містити мову без власного тексту ліцензії');
        $components = static::getContainer()->get(ThirdPartyComponents::class)->all();
        self::assertGreaterThan(50, \count($components));
        $siteName = (string) static::getContainer()->get(\App\Core\Shared\Site\SiteName::class);
        $urlOf = fn (string $locale): string => $this->host.($locale === $default ? '' : '/'.$locale).'/license';

        foreach ($locales as $locale) {
            $crawler = $this->client->request('GET', $urlOf($locale));
            self::assertResponseIsSuccessful($locale);
            $html = (string) $this->client->getResponse()->getContent();
            self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'), $locale);
            self::assertSame($urlOf($locale), $crawler->filter('link[rel="canonical"]')->attr('href'), $locale);
            self::assertCount(\count($locales), $crawler->filter('link[rel="alternate"][hreflang]:not([hreflang="x-default"])'), 'hreflang на кожну мову — сторінка є всіма');
            self::assertSame($urlOf($default), $crawler->filter('link[rel="alternate"][hreflang="x-default"]')->attr('href'));

            $expectedHeading = 'uk' === $locale ? 'Ліцензія' : 'Licence';
            self::assertSame($expectedHeading, $crawler->filter('article.license h1')->text(), "{$locale}: текст мовою сторінки або en");
            self::assertCount('uk' === $locale || 'en' === $locale ? 0 : 1, $crawler->filter('.license-note'), "{$locale}: примітка про англійський текст лише для мов без власного");
            $article = $crawler->filter('article.license')->text();
            self::assertStringContainsString(static::getContainer()->getParameter('site_host'), $article, '{site} → домен сайту');
            self::assertStringContainsString('('.$siteName.')', $article, 'без app.license власник = назва магазину');
            self::assertStringNotContainsString('{site}', $article);
            self::assertStringNotContainsString('{licensee}', $article);
            self::assertStringContainsString('uk' === $locale ? 'під ліцензією Apache 2.0' : 'licensed under Apache 2.0', $article, "{$locale}: текст під Apache 2.0");
            self::assertCount(1, $crawler->filter('article.license a[href="https://www.apache.org/licenses/LICENSE-2.0"]'), $locale);
            // лише текст ліцензії: у таблиці компонентів ліцензія пакета своя (аддон luxsite/gmb-reviews-* у збірці магазину — proprietary, docs/addons.md)
            $terms = mb_strtolower(implode(' ', $crawler->filter('article.license > :not(table)')->each(static fn ($n): string => $n->text())));
            foreach (['належать права', 'належать майнові', 'пропрієтар', 'письмової згоди', 'owns the rights', 'proprietary', 'written consent'] as $phrase) {
                self::assertStringNotContainsString($phrase, $terms, "{$locale}: текст старої пропрієтарної ліцензії прибрано");
            }
            $articleHtml = (string) $crawler->filter('article.license')->outerHtml();
            self::assertStringNotContainsString('mailto:', $articleHtml, 'наших контактів на сторінці нема');
            self::assertStringNotContainsString('tel:', $articleHtml);
            self::assertCount(1, $crawler->filter('article.license a[href="https://luxsite.agency/"]'), 'лише посилання на сайт luxsite.agency');

            $rows = $crawler->filter('table.license-components tbody tr');
            self::assertCount(\count($components), $rows, $locale);
            $rowsText = implode(' ', $rows->each(static fn ($n): string => $n->text()));
            self::assertStringContainsString('symfony/framework-bundle', $rowsText);
            self::assertStringContainsString('easycorp/easyadmin-bundle', $rowsText);
            self::assertCount(2, $crawler->filter('table.license-components thead th'), 'на сайті лише назва й ліцензія');
            foreach ($components as $component) {
                self::assertStringNotContainsString(' '.$component['version'].' ', ' '.$rowsText.' ', "версія {$component['name']} на публічній сторінці не показується (рев'ю #67 M1)");
            }
            self::assertStringNotContainsString('"ecommerce"', $html, 'без e-commerce подій аналітики');
            self::assertStringNotContainsString('view_item', $html);
        }
    }

    public function testLicenseIsNotInSitemap(): void
    {
        $this->client->request('GET', $this->host.'/sitemap.xml');
        self::assertResponseIsSuccessful();
        $xml = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<loc>', $xml);
        self::assertStringNotContainsString('/license', $xml);
    }

    public function testPackThemeCannotOverrideLicensePage(): void
    {
        self::assertTrue(StoreTemplates::isForbidden('site/page/license.html.twig'));
        self::assertFalse(StoreTemplates::isForbidden('site/page/show.html.twig'));
        self::assertFileExists(\dirname(__DIR__, 3).'/templates/license/text.uk.html.twig');
        self::assertFileExists(\dirname(__DIR__, 3).'/templates/license/text.en.html.twig');
        self::assertFalse(StoreTemplates::isAllowed('license/text.uk.html.twig'), 'текст ліцензії поза site/** — пак не перекриває');
    }

    /** Тексти ліцензії під Apache 2.0 (рішення власника 17.10.2026): ті самі пункти uk/en, жодних плейсхолдерів, крім site/licensee. */
    public function testLicenseTextsKeepTheSourceWording(): void
    {
        $uk = (string) file_get_contents(\dirname(__DIR__, 3).'/templates/license/text.uk.html.twig');
        $en = (string) file_get_contents(\dirname(__DIR__, 3).'/templates/license/text.en.html.twig');
        foreach (['Ліцензія коду.', 'Файл NOTICE.', 'Що належить власнику сайту.', 'Назва LuxSite.', 'Гарантії.', 'Сторонні компоненти.'] as $clause) {
            self::assertStringContainsString('<strong>'.$clause.'</strong>', $uk);
        }
        foreach (['Code licence.', 'NOTICE file.', 'What belongs to the site owner.', 'The LuxSite name.', 'Warranty.', 'Third-party components.'] as $clause) {
            self::assertStringContainsString('<strong>'.$clause.'</strong>', $en);
        }
        foreach ([$uk, $en] as $text) {
            self::assertStringContainsString('{{ site }}', $text);
            self::assertStringContainsString('{{ licensee }}', $text);
            self::assertDoesNotMatchRegularExpression('/\{(?!\{|%|#)[a-z_]+\}/', $text, 'плейсхолдери джерела замінено на змінні Twig');
        }
    }
}
