<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Search\ProductSearch;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Сторінка пошуку /search?q=: id з ProductSearch (назва мовою сторінки, SKU за префіксом, бренд) → лістинг read-index
 * з фасетами, фільтрами, сортуванням і сторінками; завжди noindex; порожній/закороткий q — форма без результатів.
 */
final class SearchPageTest extends CatalogPageTestCase
{
    public function testFindsByNameInPageLocaleSkuPrefixAndBrand(): void
    {
        foreach (self::locales() as $locale) {
            // назва «<locale> P1»: слово «P1» коротше за MIN_WORD → LIKE по назві
            $crawler = $this->get($this->url('site_search', ['q' => 'P1', '_locale' => $locale]));
            self::assertResponseIsSuccessful($locale);
            self::assertSame(['P1'], $this->cardNames($crawler, $locale), $locale.': лише P1 за назвою');
            self::assertSame('1', $crawler->filter('.found')->attr('data-total'));
            self::assertCount(1, $crawler->filter('meta[name="robots"][content*="noindex"]'), 'пошук не індексується');
            self::assertSame($this->url('site_search', ['q' => 'P1', '_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL), $crawler->filter('link[rel="canonical"]')->attr('href'));
            self::assertSame('P1', $crawler->filter('form.search-form input[name="q"]')->attr('value'));
        }
        $locale = self::defaultLocale();

        // SKU за префіксом: варіанти P2-<suffix>; «P2» без суфікса
        $crawler = $this->get($this->url('site_search', ['q' => 'P2-'.$this->suffix, '_locale' => $locale]));
        self::assertSame(['P2'], $this->cardNames($crawler, $locale), 'за SKU');

        // бренд: Nike <suffix> → P1 (running), P3 (sneakers), P6 (shoes); P4 вимкнений не показується і так, він Puma
        $crawler = $this->get($this->url('site_search', ['q' => 'Nike '.$this->suffix, '_locale' => $locale]));
        self::assertSame(['P3', 'P1', 'P6'], $this->cardNames($crawler, $locale), 'за брендом, ціна за зростанням');

        // слово ≥ 3 символів — FULLTEXT з префіксом: «sneak» → жодного (назви «uk P1»…), а суфікс слага не в назві
        $crawler = $this->get($this->url('site_search', ['q' => 'zzz'.$this->suffix, '_locale' => $locale]));
        self::assertSame([], $this->cardNames($crawler, $locale));
        self::assertStringContainsString('zzz'.$this->suffix, $crawler->filter('.empty')->text());
        self::assertSame('0', $crawler->filter('.found')->attr('data-total'));
    }

    public function testFiltersSortAndPagesWorkOnSearchResults(): void
    {
        $locale = self::defaultLocale();
        $brand = 'Nike '.$this->suffix;
        $crawler = $this->get($this->url('site_search', ['q' => $brand, '_locale' => $locale]));
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.facets fieldset')->count(), 'фасети всіх filterable-характеристик');
        self::assertStringContainsString('q='.rawurlencode($brand), (string) $crawler->filter('.facets a')->first()->attr('href'), 'посилання фасетів зберігають q');

        $crawler = $this->get($this->url('site_search', ['q' => $brand, 'in_stock' => '1', '_locale' => $locale]));
        self::assertSame(['P1', 'P6'], $this->cardNames($crawler, $locale), 'P3 під замовлення відпав');
        self::assertCount(1, $crawler->filter('.facets a.reset'));

        $crawler = $this->get($this->url('site_search', ['q' => $brand, 'sort' => 'price_desc', '_locale' => $locale]));
        self::assertSame(['P6', 'P1', 'P3'], $this->cardNames($crawler, $locale));

        $this->client->request('GET', $this->url('site_search', ['q' => $brand, 'page' => '9', '_locale' => $locale]));
        self::assertResponseStatusCodeSame(404, 'сторінка за межами');
        $this->client->request('GET', $this->url('site_search', ['q' => $brand, '_locale' => $locale]).'&page=abc');
        self::assertResponseStatusCodeSame(400);
    }

    public function testEmptyOrShortQueryShowsFormOnly(): void
    {
        $locale = self::defaultLocale();
        foreach (['', 'x', '   '] as $q) {
            $crawler = $this->get($this->url('site_search', ['q' => $q, '_locale' => $locale]));
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('form.search-form'));
            self::assertCount(0, $crawler->filter('.listing'), 'без результатів і фасетів');
        }
        self::assertSame('', ProductSearch::normalize(" \t a "));
        self::assertSame('ab cd', ProductSearch::normalize("  ab \n cd "));
        self::assertSame(ProductSearch::MAX_LENGTH, mb_strlen(ProductSearch::normalize(str_repeat('a', 500))));
        self::assertSame('+abc* +de12*', ProductSearch::booleanQuery('abc de12 x +*'), 'оператори FULLTEXT з URL не проходять');
        self::assertNull(ProductSearch::booleanQuery('ab 12'));
    }

    /** @return list<string> назви карток без мовного префікса, у порядку сторінки */
    private function cardNames(Crawler $crawler, string $locale): array
    {
        return $crawler->filter('.card h3')->each(static fn (Crawler $h): string => substr(trim($h->text()), \strlen($locale) + 1));
    }
}
