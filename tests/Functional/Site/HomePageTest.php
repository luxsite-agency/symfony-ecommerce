<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Entity\Media;
use App\Core\Catalog\Entity\Product;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Головна в дефолтній темі рушія: банер, плитки категорій, вітрина (обрані + новинки), CSS теми без preload шрифтів. */
final class HomePageTest extends CatalogPageTestCase
{
    public function testHomeShowsCategoriesNewestProductsAndThemeStyles(): void
    {
        $locale = self::defaultLocale();
        $crawler = $this->get($this->url('site_home', ['_locale' => $locale]));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertCount(1, $crawler->filter('.hero h1'));
        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="/assets/site.css?v="]'), 'CSS рушія з версією за вмістом');
        self::assertStringContainsString(':root{--c-bg:#ffffff;', $html, 'дефолтна світла тема — CSS-змінні з параметра');
        self::assertDoesNotMatchRegularExpression('/rel="preload"[^>]*as="font"|as="font"[^>]*rel="preload"/', $html, 'шрифти без preload (PSI)');

        self::assertStringContainsString($locale.' shoes', $crawler->filter('.tiles')->text(), 'коренева категорія — плиткою');
        $card = $crawler->filter('.product-grid .card[data-product-id="'.$this->fx->id('P6').'"]');
        self::assertCount(1, $card, 'найновіші товари — у «Новинках»');
        self::assertSame($this->trans('site.home.newest', $locale), $crawler->filter('#new-title')->text(), 'без обраних — «Новинки»');
        self::assertSame($this->productUrl($this->fx->p['P6'], $locale), $card->filter('h3 a')->attr('href'));
        self::assertCount(0, $crawler->filter('.cart-count'), 'порожній кошик — без лічильника');
        self::assertCount(0, $crawler->filter('#support'), 'блок «куди йдуть кошти» у дефолтній темі вимкнено');
    }

    /** SEO п. 9/20: title і description головної — з HomeSeo (без hero — вступ з перекладів), JSON-LD OnlineStore. */
    public function testHomeRendersSeoTitleDescriptionAndOnlineStoreSchema(): void
    {
        foreach (self::locales() as $locale) {
            $crawler = $this->get($this->url('site_home', ['_locale' => $locale]));
            self::assertResponseIsSuccessful($locale);
            $seo = static::getContainer()->get(\App\Site\Twig\SeoExtension::class)->homeSeo($locale);
            self::assertSame($seo['title'], $crawler->filter('title')->text(), $locale);
            self::assertSame($this->trans('site.home.intro', $locale), $crawler->filter('meta[name="description"]')->attr('content'), 'дефолтна тема без hero.text — вступ головної');
            $ld = implode(' ', $crawler->filter('script[type="application/ld+json"]')->each(static fn (Crawler $n): string => $n->text()));
            self::assertStringContainsString('"@type":"OnlineStore"', $ld, $locale);
            self::assertStringContainsString('"@type":"WebSite"', $ld, 'WebSite з base лишається');
        }
    }

    public function testCategoryTileUsesOwnImageElseFirstProductPhoto(): void
    {
        $locale = self::defaultLocale();
        // P1 — найстаріший товар піддерева «shoes» (running → sneakers → shoes), P6 — найновіший
        $this->photo($this->fx->p['P1'], 'p1');
        $this->photo($this->fx->p['P6'], 'p6');
        $this->photo($this->fx->p['P5'], 'p5');
        $own = new Media('bags-own-'.$this->suffix.'.jpg', 'image/jpeg', 1000);
        $this->em()->persist($own);
        $this->fx->bags->setImage($own);
        $this->em()->flush();

        $crawler = $this->get($this->url('site_home', ['_locale' => $locale]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('p1-'.$this->suffix.'.jpg', $this->tileImage($crawler, $locale.' shoes'), 'без власного зображення — фото першого товару, не найновішого');
        self::assertStringContainsString('bags-own-'.$this->suffix.'.jpg', $this->tileImage($crawler, $locale.' bags'), 'власне зображення категорії перекриває фото товару');
    }

    public function testShowcaseFeaturedFirstThenNewestPhotolessLast(): void
    {
        $locale = self::defaultLocale();
        foreach (['P1', 'P2', 'P3'] as $name) {
            $this->photo($this->fx->p[$name], strtolower($name));
        }
        $this->fx->p['P2']->setFeatured(true); // обраний з фото — першим
        $this->fx->p['P6']->setFeatured(true); // обраний без фото — після всіх з фото
        $this->em()->flush();

        $crawler = $this->get($this->url('site_home', ['_locale' => $locale]));
        self::assertResponseIsSuccessful();
        self::assertSame($this->trans('site.home.popular', $locale), $crawler->filter('#new-title')->text(), 'є обрані — «Популярне»');
        $byId = array_flip(array_map(static fn (Product $p): int => (int) $p->getId(), $this->fx->p));
        $shown = array_values(array_filter(array_map(
            static fn (string $id): ?string => $byId[(int) $id] ?? null,
            $crawler->filter('.product-grid .card')->each(static fn (Crawler $c): string => (string) $c->attr('data-product-id')),
        )));
        // P4 вимкнений; P5 без фото і не обраний — останнім
        self::assertSame(['P2', 'P3', 'P1', 'P6', 'P5'], $shown);
    }

    private function photo(Product $product, string $name): void
    {
        $media = new Media($name.'-'.$this->suffix.'.jpg', 'image/jpeg', 1000);
        $this->em()->persist($media);
        $product->addMedia($media);
    }

    private function tileImage(Crawler $crawler, string $name): string
    {
        $tile = $crawler->filter('.tiles a.tile')->reduce(static fn (Crawler $t): bool => str_contains($t->text(), $name));
        self::assertCount(1, $tile, 'плитка '.$name);

        return (string) $tile->filter('img')->attr('src');
    }

    private function trans(string $key, string $locale): string
    {
        return static::getContainer()->get(TranslatorInterface::class)->trans($key, [], null, $locale);
    }
}
