<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\FilterIndex\FilterIndexer;
use App\Core\Catalog\Query\CatalogCacheVersion;
use App\Tests\Integration\Core\Catalog\FilterIndex\CatalogIndexFixture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Публічний каталог на фікстурі CatalogIndexFixture з перекладами всіма мовами app.locales (мови беруться з параметра,
 * не з рядків — тестовий набір мов може бути ширшим за дефолт). Read-index будується вручну (Messenger у тестах
 * in-memory), версія кешу — bump, щоб результати попередніх тестів не підхоплювались.
 */
abstract class CatalogPageTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected CatalogIndexFixture $fx;
    protected string $suffix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects(false);
        $this->suffix = bin2hex(random_bytes(3));
        $this->fx = new CatalogIndexFixture($this->em(), $this->suffix);
        foreach ([$this->fx->shoes, $this->fx->sneakers, $this->fx->running, $this->fx->bags] as $category) {
            $this->translateCategory($category);
        }
        foreach ($this->fx->p as $name => $product) {
            $this->translateProduct($product, $name);
        }
        foreach ([$this->fx->size, $this->fx->color, $this->fx->material, $this->fx->weight, $this->fx->waterproof] as $attribute) {
            $this->fx->shoes->addFilterAttribute($attribute);
            foreach (self::locales() as $locale) {
                $attribute->translate($locale)->setName($locale.':'.$attribute->getCode());
            }
        }
        $this->em()->flush();
        $this->reindex($this->fx->productIds());
    }

    /** @return list<string> */
    protected static function locales(): array
    {
        /** @var list<string> $locales */
        $locales = static::getContainer()->getParameter('app.locales');

        return $locales;
    }

    protected static function defaultLocale(): string
    {
        /** @var string $locale */
        $locale = static::getContainer()->getParameter('app.default_locale');

        return $locale;
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<int> $productIds */
    protected function reindex(array $productIds): void
    {
        static::getContainer()->get(FilterIndexer::class)->reindexProducts($productIds);
        static::getContainer()->get(CatalogCacheVersion::class)->bump();
    }

    protected function categorySlug(Category $category, string $locale): string
    {
        return (string) $category->getTranslation($locale)?->getSlug();
    }

    /** @param array<string, mixed> $query */
    protected function categoryUrl(Category $category, string $locale, array $query = [], int $type = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return $this->url('site_category', ['path' => (string) $category->getTranslation($locale)?->getPath(), '_locale' => $locale] + $query, $type);
    }

    /** @param array<string, mixed> $query */
    protected function productUrl(Product $product, string $locale, array $query = [], int $type = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return $this->url('site_product', ['slug' => (string) $product->getTranslation($locale)?->getSlug(), '_locale' => $locale] + $query, $type);
    }

    /**
     * ABSOLUTE_URL — як canonical/hreflang сайту: канонічний домен site_host (маршрути site_* без host), не хост запиту.
     *
     * @param array<string, mixed> $params
     */
    protected function url(string $route, array $params, int $type = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        $path = static::getContainer()->get(UrlGeneratorInterface::class)->generate($route, $params);
        if (UrlGeneratorInterface::ABSOLUTE_URL !== $type) {
            return $path;
        }
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');

        return 'https://'.$host.$path;
    }

    /** Код hreflang мови (app.hreflang: uk → uk-UA), як його друкує base.html.twig. */
    protected static function hreflangCode(string $locale): string
    {
        /** @var array<string, string> $map */
        $map = static::getContainer()->getParameter('app.hreflang');

        return $map[$locale] ?? $locale;
    }

    /**
     * Очікуваний набір hreflang: усі мови app.locales + x-default (якщо мов більше однієї).
     *
     * @param callable(string): string $urlFor мова → абсолютний URL
     *
     * @return array<string, string>
     */
    protected static function expectedHreflang(callable $urlFor): array
    {
        $expected = [];
        foreach (self::locales() as $locale) {
            $expected[self::hreflangCode($locale)] = $urlFor($locale);
        }
        if (\count($expected) > 1) {
            $expected['x-default'] = $urlFor(self::defaultLocale());
        }

        return $expected;
    }

    protected function get(string $url): Crawler
    {
        return $this->client->request('GET', $url);
    }

    /** @return array<string, string> hreflang → href */
    protected static function hreflang(Crawler $crawler): array
    {
        $links = [];
        $crawler->filter('link[rel="alternate"][hreflang]')->each(static function (Crawler $link) use (&$links): void {
            $links[(string) $link->attr('hreflang')] = (string) $link->attr('href');
        });

        return $links;
    }

    protected function translateCategory(Category $category): void
    {
        // базова назва — з перекладу, який створила CatalogIndexFixture (shoes, sneakers…)
        $base = (string) $category->getTranslation($category->getTranslatedLocales()[0])?->getName();
        foreach (self::locales() as $locale) {
            $category->translate($locale)->setName($locale.' '.$base)->setSlug($locale.'-'.$base.'-'.$this->suffix);
        }
    }

    protected function translateProduct(Product $product, string $name): void
    {
        foreach (self::locales() as $locale) {
            $product->translate($locale)->setName($locale.' '.$name)->setSlug($locale.'-'.strtolower($name).'-'.$this->suffix);
        }
    }
}
