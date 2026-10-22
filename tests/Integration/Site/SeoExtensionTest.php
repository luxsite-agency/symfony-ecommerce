<?php

declare(strict_types=1);

namespace App\Tests\Integration\Site;

use App\Core\Shared\Site\SiteName;
use App\Site\Seo\HomeSeo;
use App\Site\Seo\OrganizationSchema;
use App\Site\SiteUrlGenerator;
use App\Site\Theme\SiteTheme;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** SEO головної з параметрів теми (п. 9) і OnlineStore без порожніх полів (п. 20). */
final class SeoExtensionTest extends KernelTestCase
{
    public function testHomeTitleAndDescriptionFromThemeWithFallbacks(): void
    {
        self::bootKernel();
        $siteName = self::getContainer()->get(SiteName::class);
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $themed = new HomeSeo(new SiteTheme([
            'logo' => ['src' => 'themes/x/logo.svg'],
            'brand' => ['name' => ['uk' => 'Мерч Сокіл 77', 'en' => 'Sokil Store'], 'tagline' => ['uk' => 'Лісова', 'en' => 'Forest']],
            'hero' => ['text' => ['uk' => 'Текст банера']],
            'seo' => ['home_title' => ['uk' => 'Мерч Сокіл 77 — офіційний магазин команди'], 'home_description' => ['uk' => 'Футболки, шеврони та сувеніри Сокіл 77. Кошти від продажу спрямовуються на потреби команди.']],
        ]), $siteName, $translator);
        self::assertSame('Мерч Сокіл 77 — офіційний магазин команди', $themed->title('uk', 'uk'));
        self::assertStringStartsWith('Футболки, шеврони', $themed->description('uk', 'uk'));
        self::assertSame('Sokil Store | Forest', $themed->title('en', 'uk'), 'en без seo-полів — brand | tagline');
        self::assertSame('Текст банера', $themed->description('en', 'uk'), 'Опис — з hero.text з фолбеком на мову за замовчуванням');

        $plain = new HomeSeo(new SiteTheme(['logo' => ['src' => 'themes/x/logo.svg']]), $siteName, $translator);
        self::assertSame($siteName->forLocale('uk'), $plain->title('uk', 'uk'), 'Без теми — назва магазину');
        self::assertSame($translator->trans('site.home.intro', [], null, 'uk'), $plain->description('uk', 'uk'), 'Без hero.text — вступ головної з перекладів');
        self::assertSame($translator->trans('site.home.intro', [], null, 'en'), $plain->description('en', 'uk'));
        self::assertNotSame($plain->description('uk', 'uk'), $plain->description('en', 'uk'), 'мовою сторінки');
    }

    public function testOrganizationSchemaOmitsEmptyFields(): void
    {
        self::bootKernel();
        $siteName = self::getContainer()->get(SiteName::class);
        $urls = self::getContainer()->get(SiteUrlGenerator::class);
        $home = $urls->home('uk');

        $empty = (new OrganizationSchema(new SiteTheme(['brand' => ['name' => 'Мерч'], 'logo' => ['src' => 'themes/x/logo.svg']]), $siteName, $urls))->build('uk', 'uk');
        self::assertSame(['@context', '@type', '@id', 'name', 'url', 'logo', 'areaServed'], array_keys($empty), 'Без реквізитів — лише назва, URL, лого (обов\'язкове) і регіон; порожніх ключів немає');
        self::assertSame('OnlineStore', $empty['@type']);
        self::assertSame($home, $empty['url']);
        self::assertArrayNotHasKey('parentOrganization', $empty);

        $full = (new OrganizationSchema(new SiteTheme([
            'brand' => ['name' => 'Мерч Сокіл 77'],
            'logo' => ['src' => 'themes/legacyshop/emblem-104.webp'],
            'footer' => ['phone' => '+380671234567'],
            'organization' => ['legal_name' => 'ФОП Іваненко І. І.', 'tax_id' => '1234567890', 'email' => 'shop@example.com', 'address' => ['city' => 'Житомир', 'country' => 'UA'], 'same_as' => ['https://instagram.com/x', '']],
        ]), $siteName, $urls))->build('uk', 'uk');
        self::assertSame('ФОП Іваненко І. І.', $full['legalName']);
        self::assertSame('1234567890', $full['taxID']);
        self::assertSame(rtrim($home, '/').'/themes/legacyshop/emblem-104.webp', $full['logo']);
        self::assertSame(['@type' => 'ContactPoint', 'contactType' => 'customer service', 'telephone' => '+380671234567', 'email' => 'shop@example.com', 'areaServed' => 'UA'], $full['contactPoint'], 'Телефон — фолбек на footer.phone');
        self::assertSame(['@type' => 'PostalAddress', 'addressLocality' => 'Житомир', 'addressCountry' => 'UA'], $full['address']);
        self::assertSame(['https://instagram.com/x'], $full['sameAs']);

        foreach (['https://cdn.example.com/logo.png' => 'https://cdn.example.com/logo.png', '//cdn.example.com/l.svg' => 'https://cdn.example.com/l.svg', '/themes/x/logo.svg' => rtrim($home, '/').'/themes/x/logo.svg'] as $src => $expected) {
            $logo = (new OrganizationSchema(new SiteTheme(['logo' => ['src' => $src]]), $siteName, $urls))->build('uk', 'uk')['logo'];
            self::assertSame($expected, $logo, 'абсолютну адресу логотипа не склеювати з home');
        }

        // макрос партіала виводить JSON-LD через site_organization_schema() (тема контейнера — дефолтна)
        $html = self::getContainer()->get(Environment::class)->createTemplate("{% import 'site/partials/_schema_organization.html.twig' as org %}{{ org.onlineStore() }}")->render();
        self::assertStringContainsString('"@type":"OnlineStore"', $html);
        self::assertStringContainsString('"areaServed":"UA"', $html);
        self::assertStringNotContainsString('"legalName"', $html);
    }
}
