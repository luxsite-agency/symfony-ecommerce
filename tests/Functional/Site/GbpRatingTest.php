<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Shared\Site\SiteName;
use App\Site\Seo\OrganizationSchema;
use App\Site\SiteUrlGenerator;
use App\Site\Theme\SiteTheme;
use Luxsite\GmbReviews\Client;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Віджет відгуків gbp-manager (власник 18.10.2026): загальна оцінка (рейтинг + кількість, джерело — налаштування віджета)
 * у кожному блоці відгуків — на головній блок відгуків компанії, на картці товару рядок «Оцінка магазину»; aggregateRating
 * магазину на сайті один — в OnlineStore головної, з тими ж числами; Product.aggregateRating товару — власний, окремо.
 * Пакет необов'язковий (docs/addons.md): без нього тести пропускаються, стан «без пакета» — GmbReviewsOptionalTest.
 */
final class GbpRatingTest extends CatalogPageTestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        if (!class_exists(Client::class)) {
            self::markTestSkipped('luxsite/gmb-reviews-bundle не встановлено (необов\'язковий пакет; local/addon.sh gmb-reviews on)');
        }
        parent::setUp();
    }

    /** @param array<string, mixed> $summary */
    private function fakeWidget(array $summary, bool $schema = true): void
    {
        $body = (string) json_encode([
            'business' => ['name' => 'Магазин', 'rating' => 4.8, 'count' => 9, 'maps_url' => 'https://maps.google.com/?cid=7'],
            'settings' => ['layout' => 'grid', 'theme' => 'auto', 'lang' => 'auto', 'title' => '', 'accent' => '#0d6e6e', 'schema_markup' => $schema],
            'summary' => $summary,
            'form' => ['enabled' => false],
            'reviews' => [['id' => 'g1', 'source' => 'google', 'author' => 'Ірина', 'rating' => 5, 'text' => 'Швидко доставили.', 'date' => '2026-09-01', 'pinned' => false, 'reply' => null]],
        ]);
        $this->client->disableReboot();
        static::getContainer()->set(Client::class, new Client('https://gbp.test', null, 600,
            static fn (string $url): ?string => str_ends_with($url, '/'.self::TOKEN.'.json') ? $body : null,
            sys_get_temp_dir().'/gbp-test-'.bin2hex(random_bytes(4))));
    }

    /** @return list<array<string, mixed>> */
    private static function lds(Crawler $crawler): array
    {
        return $crawler->filter('script[type="application/ld+json"]')->each(static fn (Crawler $n): array => (array) json_decode($n->text(), true));
    }

    public function testHomeShowsStoreReviewsAndOneAggregateRatingWithSameNumbers(): void
    {
        $this->fakeWidget(['show' => true, 'rating' => 4.9, 'count' => 102, 'includes_site' => true]);
        $crawler = $this->get($this->url('site_home', ['_locale' => self::defaultLocale()]));
        self::assertResponseIsSuccessful();

        $block = $crawler->filter('#store-reviews .gmbr.gmbr-theme-site');
        self::assertCount(1, $block, 'блок відгуків компанії на головній');
        self::assertSame('4.9', $block->filter('.gmbr-agg b')->text());
        self::assertStringContainsString('102', $block->filter('.gmbr-agg .gmbr-cnt')->text());
        self::assertStringContainsString('Швидко доставили.', $block->text());

        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, substr_count($html, '"aggregateRating"'), 'на сторінці один aggregateRating');
        self::assertStringNotContainsString('LocalBusiness', $html, 'окремого LocalBusiness віджета нема — оцінка в OnlineStore');
        $store = array_values(array_filter(self::lds($crawler), static fn (array $ld): bool => 'OnlineStore' === ($ld['@type'] ?? null)))[0];
        self::assertSame(['@type' => 'AggregateRating', 'ratingValue' => '4.9', 'reviewCount' => '102', 'bestRating' => '5', 'worstRating' => '1'], $store['aggregateRating']);
    }

    public function testProductReviewsShowStoreRatingWithoutMarkup(): void
    {
        $this->fakeWidget(['show' => true, 'rating' => 4.8, 'count' => 9, 'includes_site' => false]);
        $crawler = $this->get($this->productUrl($this->fx->p['P1'], self::defaultLocale()));
        self::assertResponseIsSuccessful();

        $line = $crawler->filter('#reviews .reviews-store');
        self::assertCount(1, $line, 'рядок оцінки магазину в блоці відгуків товару');
        self::assertSame('4.8', $line->filter('.gmbr-rating .gmbr-rating-value')->text());
        self::assertStringContainsString('9', $line->filter('.gmbr-rating-count')->text());
        self::assertSame('https://maps.google.com/?cid=7', $line->filter('.gmbr-rating a')->attr('href'), 'лише Google — посилання на картку');
        self::assertStringNotContainsString('"aggregateRating"', (string) $this->client->getResponse()->getContent(), 'товар без відгуків: ні Product-, ні магазинної розмітки');
    }

    public function testSummaryOffHidesRatingAndMarkup(): void
    {
        $this->fakeWidget(['show' => false, 'rating' => 4.9, 'count' => 102, 'includes_site' => false]);
        $home = $this->get($this->url('site_home', ['_locale' => self::defaultLocale()]));
        self::assertCount(1, $home->filter('#store-reviews'), 'відгуки показуються');
        self::assertCount(0, $home->filter('#store-reviews .gmbr-agg'), 'загальну оцінку вимкнено у віджеті');
        self::assertStringNotContainsString('"aggregateRating"', (string) $this->client->getResponse()->getContent());

        $product = $this->get($this->productUrl($this->fx->p['P1'], self::defaultLocale()));
        self::assertCount(0, $product->filter('.reviews-store'));
    }

    public function testSchemaMarkupOffKeepsVisibleRatingOnly(): void
    {
        $this->fakeWidget(['show' => true, 'rating' => 4.9, 'count' => 102, 'includes_site' => false], false);
        $home = $this->get($this->url('site_home', ['_locale' => self::defaultLocale()]));
        self::assertCount(1, $home->filter('#store-reviews .gmbr-agg'));
        self::assertStringNotContainsString('"aggregateRating"', (string) $this->client->getResponse()->getContent(), 'мікророзмітку вимкнено у віджеті');
    }

    public function testUnavailableWidgetRendersNothing(): void
    {
        // gbp-manager у тестах — 127.0.0.1:9 (з'єднання відхилено), кешу нема → блоку й розмітки нема, сторінка працює
        $home = $this->get($this->url('site_home', ['_locale' => self::defaultLocale()]));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $home->filter('#store-reviews'));
        self::assertStringNotContainsString('"aggregateRating"', (string) $this->client->getResponse()->getContent());
    }

    public function testDisabledByDefaultEmptyToken(): void
    {
        $client = new Client('https://gbp.test', null, 600, static fn (): string => (string) json_encode(['business' => ['rating' => 5, 'count' => 3], 'settings' => ['schema_markup' => true], 'reviews' => []]), sys_get_temp_dir().'/gbp-test-'.bin2hex(random_bytes(4)));
        $container = static::getContainer();
        $schema = new OrganizationSchema(new SiteTheme(['logo' => ['src' => 'themes/x/logo.svg']]), $container->get(SiteName::class), $container->get(SiteUrlGenerator::class), $client, '');
        self::assertArrayNotHasKey('aggregateRating', $schema->build('uk', 'uk'), 'порожній токен — вимкнено');

        $engineDefaults = \Symfony\Component\Yaml\Yaml::parseFile(\dirname(__DIR__, 3).'/config/services.yaml')['parameters'];
        self::assertSame('', $engineDefaults['app.gbp_reviews.token'], 'у рушії типово вимкнено');
    }
}
