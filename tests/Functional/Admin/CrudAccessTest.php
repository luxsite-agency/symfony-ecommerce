<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\Brand;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Enum\AttributeType;
use App\Core\Security\Enum\UserRole;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

/** Доступ за ролями (ADR D10) і робота CRUD-форм поверх доменних сутностей. */
final class CrudAccessTest extends AdminWebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function catalogPages(): iterable
    {
        foreach (['/category', '/category/new', '/brand', '/brand/new', '/attribute', '/attribute/new', '/library', '/library/new', '/order'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('catalogPages')]
    public function testManagerOpensCatalogPages(string $path): void
    {
        $this->loginAs(UserRole::Manager);
        $this->client->request('GET', $this->adminUrl($path));
        self::assertResponseIsSuccessful($path);
    }

    /** @return iterable<string, array{string}> */
    public static function settingsPages(): iterable
    {
        foreach (['/user', '/user/new', '/customer-group', '/customer-group/new', '/setting'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('settingsPages')]
    public function testManagerIsForbiddenFromSettings(string $path): void
    {
        $this->loginAs(UserRole::Manager);
        $this->client->request('GET', $this->adminUrl($path));
        self::assertResponseStatusCodeSame(403, $path);
    }

    #[DataProvider('settingsPages')]
    public function testAdminOpensSettings(string $path): void
    {
        $this->loginAs(UserRole::Admin);
        $this->client->request('GET', $this->adminUrl($path));
        self::assertResponseIsSuccessful($path);
    }

    public function testSettingsListFlagsInvalidIdFormat(): void
    {
        $this->loginAs(UserRole::Admin, 'admin@example.test');
        $settings = static::getContainer()->get(\App\Core\Settings\Settings::class);
        $settings->set(\App\Core\Settings\SettingKeys::GTM_CONTAINER_ID, 'not-an-id');
        $settings->set(\App\Core\Settings\SettingKeys::FB_PIXEL_ID, '123456789012');
        $crawler = $this->client->request('GET', $this->adminUrl('/setting'));
        self::assertResponseIsSuccessful();
        $text = $crawler->filter('table tbody')->text();
        self::assertStringContainsString('не виводиться: невірний формат', $text);
        self::assertStringContainsString('виводиться', $text);
        self::assertStringContainsString('не задано', $text);
        $settings->invalidate();
    }

    public function testSettingsListShowsFeedUrlsWithLastGeneration(): void
    {
        $this->loginAs(UserRole::Admin, 'admin@example.test');
        /** @var list<string> $locales */
        $locales = static::getContainer()->getParameter('app.locales');
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $paths = static::getContainer()->get(\App\Site\Feed\MerchantFeedPaths::class);
        static::getContainer()->get(\App\Site\Feed\MerchantFeedGenerator::class)->generate($locales[0]);
        @unlink($paths->file($locales[1], \App\Site\Feed\FeedChannel::Meta));

        $crawler = $this->client->request('GET', $this->adminUrl('/setting'));
        self::assertResponseIsSuccessful();
        $feeds = $crawler->filter('#product-feeds');
        self::assertCount(2 * \count($locales), $feeds->filter('tbody tr'), 'Google і Meta кожною мовою');
        $google = $feeds->filter('tr[data-feed="google-'.$locales[0].'"]');
        self::assertSame('https://'.$host.'/feeds/google-merchant-'.$locales[0].'.xml', $google->filter('input')->attr('value'));
        self::assertMatchesRegularExpression('/\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}/', $google->text(), 'щойно згенеровано — дата');
        self::assertSame('https://'.$host.'/feeds/meta-'.$locales[1].'.xml', $feeds->filter('tr[data-feed="meta-'.$locales[1].'"] input')->attr('value'));
        self::assertStringContainsString('ще не згенеровано', $feeds->filter('tr[data-feed="meta-'.$locales[1].'"]')->text());
    }

    public function testManagerMenuHidesSettings(): void
    {
        $this->loginAs(UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl('/'));
        $menu = $crawler->filter('#main-menu')->text();
        self::assertStringContainsString('Каталог', $menu);
        self::assertStringNotContainsString('Налаштування', $menu);
        self::assertStringNotContainsString('Користувачі', $menu);

        $this->logout();
        $this->loginAs(UserRole::Admin, 'admin@example.test');
        $crawler = $this->client->request('GET', $this->adminUrl('/'));
        self::assertStringContainsString('Користувачі', $crawler->filter('#main-menu')->text());
    }

    public function testCreateBrandThroughForm(): void
    {
        $this->loginAs(UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl('/brand/new'));
        $crawler = $this->submitEasyAdminForm($crawler, 'Brand', ['Brand[name]' => 'Solo Doors', 'Brand[slug]' => 'solo-doors']);
        self::assertFormRedirected($crawler);

        $brand = $this->em()->getRepository(Brand::class)->findOneBy(['slug' => 'solo-doors']);
        self::assertNotNull($brand);
        self::assertSame('Solo Doors', $brand->getName());
    }

    public function testInvalidSlugIsShownAsFieldErrorNotServerError(): void
    {
        $this->loginAs(UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl('/brand/new'));
        $crawler = $this->submitEasyAdminForm($crawler, 'Brand', ['Brand[name]' => 'Solo', 'Brand[slug]' => 'Не слаг!']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Не слаг', $crawler->filter('.invalid-feedback, .form-error-message, .alert-danger')->text());
        self::assertNull($this->em()->getRepository(Brand::class)->findOneBy(['name' => 'Solo']));
    }

    public function testCreateCategoryTreeWithTranslations(): void
    {
        $root = new Category();
        $root->translate('uk')->setName('Двері')->setSlug('dveri');
        $this->em()->persist($root);
        $this->em()->flush();

        $this->loginAs(UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl('/category/new'));
        $crawler = $this->submitEasyAdminForm($crawler, 'Category', [
            'Category[parent]' => (string) $root->getId(),
            'Category[position]' => '2',
            'Category[translationsForm][uk][name]' => 'Міжкімнатні',
            'Category[translationsForm][ru][name]' => '',
        ]);
        self::assertFormRedirected($crawler);

        $this->em()->clear();
        $created = $this->em()->getRepository(Category::class)->findOneBy(['position' => 2]);
        self::assertNotNull($created);
        self::assertSame('Двері', (string) $created->getParent());
        self::assertSame('mizhkimnatni', $created->translate('uk')->getSlug(), 'Слаг сформовано з назви транслітерацією');
        self::assertSame(['uk'], $created->getTranslatedLocales(), 'Порожній ru-переклад не збережено');
    }

    public function testCreateAttributeWithValues(): void
    {
        $this->loginAs(UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl('/attribute/new'));
        $crawler = $this->submitEasyAdminForm($crawler, 'Attribute', [
            'Attribute[code]' => 'color',
            'Attribute[type]' => 'select',
            'Attribute[variantAxis]' => '1',
            'Attribute[translationsForm][uk][name]' => 'Колір',
        ], [
            'Attribute[values][0][code]' => 'white',
            'Attribute[values][0][position]' => '0',
            'Attribute[values][0][translationsForm][uk][value]' => 'Білий',
            'Attribute[values][1][code]' => 'black',
            'Attribute[values][1][position]' => '1',
            'Attribute[values][1][translationsForm][uk][value]' => 'Чорний',
        ]);
        self::assertFormRedirected($crawler);

        $this->em()->clear();
        $attribute = $this->em()->getRepository(Attribute::class)->findOneBy(['code' => 'color']);
        self::assertNotNull($attribute);
        self::assertSame(AttributeType::Select, $attribute->getType());
        self::assertTrue($attribute->isVariantAxis());
        self::assertSame(['white', 'black'], $attribute->getValues()->map(static fn ($v): string => $v->getCode())->getValues());
        self::assertSame('Білий', $attribute->findValue('white')?->translate('uk')->getValue());
    }

    /**
     * Надсилає форму EasyAdmin як браузер: значення полів + прототипні рядки колекцій (яких нема в HTML) + кнопка.
     *
     * @param array<string, string> $values
     * @param array<string, string> $extra
     */
    private function submitEasyAdminForm(Crawler $crawler, string $formName, array $values, array $extra = []): Crawler
    {
        $form = $crawler->filter(\sprintf('form[name="%s"]', $formName))->form();
        $form->setValues($values);
        $payload = $form->getPhpValues();
        foreach ($extra as $name => $value) {
            parse_str(\sprintf('%s=%s', $name, urlencode($value)), $parsed);
            $payload = array_replace_recursive($payload, $parsed);
        }
        $payload['ea']['newForm']['btn'] = 'saveAndReturn';

        return $this->client->request($form->getMethod(), $form->getUri(), $payload, $form->getPhpFiles());
    }
}
