<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Entity\Product;
use App\Core\Content\Entity\Solution;
use App\Core\Content\Entity\SolutionGroup;
use App\Core\Content\Entity\SolutionOption;
use App\Core\Content\SolutionLocales;
use App\Core\Demo\DemoCatalog;
use App\Core\Demo\DemoPurger;
use App\Site\SiteNavigation;
use App\Site\Theme\SiteTheme;
use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Рішення на сайті (docs/solutions.md): без опублікованих — сторінок і пункту меню нема; список за групами, сторінка з варіантами
 * (картки товарів у порядку position, переваги мовою сторінки), hreflang лише мовами з перекладом, 404 для неопублікованого,
 * блок «Рішення з цим продуктом» на картці товару, sitemap.
 */
final class SolutionPagesTest extends CatalogPageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetSolutionLocales();
    }

    protected function tearDown(): void
    {
        // рядки відкочує DAMA, а кеш мов з рішеннями — ні: інакше пункт меню «Рішення» лишився б у наступних тестах
        $this->forgetSolutionLocales();
        parent::tearDown();
    }

    /** <body>: окремі класи theme-<key> і класу сторінки (base склеює theme-{{ key }} з блоком bodyClass) */
    private function assertBodyClasses(Crawler $crawler, string $pageClass): void
    {
        $classes = preg_split('/\s+/', trim((string) $crawler->filter('body')->attr('class'))) ?: [];
        self::assertContains('theme-'.static::getContainer()->get(SiteTheme::class)->get('key'), $classes);
        self::assertContains($pageClass, $classes);
    }

    private function forgetSolutionLocales(): void
    {
        static::getContainer()->get(SolutionLocales::class)->forget();
        static::getContainer()->get(SiteNavigation::class)->reset();
    }

    public function testShopWithoutSolutionsLooksAsBefore(): void
    {
        $this->em()->createQuery('DELETE FROM '.Solution::class)->execute();
        $this->forgetSolutionLocales();
        $locale = self::defaultLocale();
        $crawler = $this->client->request('GET', $this->url('site_home', ['_locale' => $locale]));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/solutions/"]'), 'без рішень пункту меню нема');
        $this->client->request('GET', $this->url('site_solutions', ['_locale' => $locale]));
        self::assertResponseStatusCodeSame(404);
        $crawler = $this->client->request('GET', $this->productUrl($this->product(0), $locale));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.product-solutions'));
    }

    public function testListPageOptionsHreflangAndProductBlock(): void
    {
        $locales = self::locales();
        [$main, $other] = [self::defaultLocale(), array_values(array_diff($locales, [self::defaultLocale()]))[0]];
        $group = new SolutionGroup();
        $group->translate($main)->setTitle('Системи зберігання '.$this->suffix);
        $this->em()->persist($group);
        $first = $this->product(0);
        $second = $this->product(1);
        $solution = $this->solution('Палетне зберігання '.$this->suffix, $main, published: true);
        $solution->setGroup($group);
        $solution->translate($other)->setTitle('Pallet '.$this->suffix);
        $solution->translate($main)->setTask('Зберігати палети')->setLoads(['EUR-палети', 'FIN-палети'])->setIndustries(['Ритейл'])
            ->setFaq([['q' => 'Скільки коштує?', 'a' => 'Після розрахунку']]);
        // порядок варіантів — position, а не порядок додавання
        $this->option($solution, $second, 1, $main, ['Друга перевага']);
        $this->option($solution, $first, 0, $main, ['Доступ до кожної палети', 'До 6000 кг']);
        $draft = $this->solution('Чернетка '.$this->suffix, $main, published: false);
        $this->option($draft, $first, 0, $main, []);
        $this->em()->flush(); // SolutionsCacheListener скидає кеш мов з рішеннями

        $slug = (string) $solution->translate($main)->getSlug();
        $crawler = $this->client->request('GET', $this->url('site_solutions', ['_locale' => $main]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Системи зберігання '.$this->suffix, $crawler->filter('.solution-group h2')->text());
        self::assertGreaterThan(0, $crawler->filter('a[href$="/solutions/'.$slug.'/"]')->count());
        self::assertStringNotContainsString('Чернетка '.$this->suffix, (string) $this->client->getResponse()->getContent());
        self::assertGreaterThan(0, $crawler->filter('.nav-inline a[href$="/solutions/"]')->count(), 'з рішеннями — пункт меню');
        $this->assertBodyClasses($crawler, 'page-solutions');

        // hreflang списку — лише мовами, де є рішення (рішення перекладено uk + $other; решта мов — 404)
        $listHreflang = $crawler->filter('link[rel="alternate"][hreflang]')->each(static fn ($n): string => (string) $n->attr('hreflang'));
        foreach (array_diff($locales, [$main, $other]) as $missing) {
            self::assertNotContains(self::hreflangCode($missing), $listHreflang, 'список без рішень цією мовою — без hreflang');
        }

        $crawler = $this->client->request('GET', $this->url('site_solution', ['slug' => $slug, '_locale' => $main]));
        self::assertResponseIsSuccessful();
        self::assertSame('Палетне зберігання '.$this->suffix, $crawler->filter('h1')->text());
        $this->assertBodyClasses($crawler, 'page-solution');
        $names = $crawler->filter('.solution-option h3')->each(static fn ($n): string => $n->text());
        self::assertSame([$main.' P1', $main.' P2'], array_map(static fn (string $n): string => preg_replace('/^(\S+ P\d).*/', '$1', $n) ?? $n, $names));
        self::assertSame(['Доступ до кожної палети', 'До 6000 кг'], $crawler->filter('.solution-option')->first()->filter('.checks li')->each(static fn ($n): string => $n->text()));
        self::assertSame(['EUR-палети', 'FIN-палети'], $crawler->filter('.solution-task ul')->first()->filter('li')->each(static fn ($n): string => $n->text()));
        self::assertCount(2, $crawler->filter('.solution-option .price'), 'ціна або «ціна за запитом» з товару');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('"@type":"ItemList"', str_replace(' ', '', $html));
        self::assertStringContainsString('"@type":"FAQPage"', str_replace(' ', '', $html));

        // hreflang: мови з перекладом (uk + інша), а мови без перекладу — ні
        $hreflang = $crawler->filter('link[rel="alternate"][hreflang]')->each(static fn ($n): string => (string) $n->attr('hreflang'));
        self::assertContains(self::hreflangCode($main), $hreflang);
        self::assertContains(self::hreflangCode($other), $hreflang);
        foreach (array_diff($locales, [$main, $other]) as $missing) {
            self::assertNotContains(self::hreflangCode($missing), $hreflang);
        }

        // без кінцевого слеша — 301 на адресу зі слешем
        $this->client->request('GET', rtrim($this->url('site_solution', ['slug' => $slug, '_locale' => $main]), '/'));
        self::assertResponseStatusCodeSame(301);
        self::assertStringEndsWith('/solutions/'.$slug.'/', (string) $this->client->getResponse()->headers->get('Location'));
        if ($main !== $other) {
            self::assertStringStartsWith('/'.$other.'/solutions/', $this->url('site_solution', ['slug' => (string) $solution->translate($other)->getSlug(), '_locale' => $other]));
        }

        // чернетка й чужий слаг — 404
        $this->client->request('GET', $this->url('site_solution', ['slug' => (string) $draft->translate($main)->getSlug(), '_locale' => $main]));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->url('site_solution', ['slug' => 'no-such-'.$this->suffix, '_locale' => $main]));
        self::assertResponseStatusCodeSame(404);

        // картка товару: блок з опублікованим рішенням, без чернетки; запити не ростуть з кількістю рішень (рев'ю #102, L1)
        $this->client->enableProfiler();
        $crawler = $this->client->request('GET', $this->productUrl($first, $main));
        $collector = $this->client->getProfile() ? $this->client->getProfile()->getCollector('db') : null;
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        $withOne = $collector->getQueryCount();
        $fresh = $this->em()->find(Product::class, $first->getId()); // клієнт перезавантажив ядро — інший EntityManager
        self::assertNotNull($fresh);
        for ($i = 0; $i < 3; ++$i) {
            $more = $this->solution('Ще одне '.$i.' '.$this->suffix, $main, published: true);
            $this->option($more, $fresh, 0, $main, []);
        }
        $this->em()->flush();
        $this->client->enableProfiler();
        $this->client->request('GET', $this->productUrl($first, $main));
        $collector = $this->client->getProfile() ? $this->client->getProfile()->getCollector('db') : null;
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        self::assertSame($withOne, $collector->getQueryCount(), '4 рішення на картці — стільки ж запитів, скільки з одним');
        $crawler = $this->client->request('GET', $this->productUrl($first, $main));
        self::assertResponseIsSuccessful();
        $block = $crawler->filter('.product-solutions');
        self::assertCount(1, $block);
        self::assertStringContainsString('Палетне зберігання '.$this->suffix, $block->text());
        self::assertStringNotContainsString('Чернетка', $block->text());

        $this->client->request('GET', '/sitemap.xml');
        $sitemap = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('/solutions/'.$slug.'/</loc>', $sitemap);
        self::assertStringNotContainsString((string) $draft->translate($main)->getSlug(), $sitemap);
    }

    public function testDemoPurgeForgetsSolutionLocales(): void
    {
        $main = self::defaultLocale();
        $demo = $this->solution('Демо '.$this->suffix, $main, published: true);
        $demo->translate($main)->setSlug(DemoCatalog::SLUG_PREFIX.'solution-'.$this->suffix);
        $this->option($demo, $this->product(0), 0, $main, []);
        $this->em()->flush();
        $navigation = static::getContainer()->get(SiteNavigation::class);
        self::assertTrue($navigation->hasSolutions($main), 'кеш заповнено');

        // --purge видаляє рішення сирим SQL, повз ORM: кеш мов з рішеннями скидає сам purger (рев'ю #102, M1)
        self::assertSame(1, static::getContainer()->get(DemoPurger::class)->purgeSolutions());
        $navigation->reset();
        self::assertFalse($navigation->hasSolutions($main), 'після --purge пункту меню «Рішення» нема');
    }

    private function product(int $index): Product
    {
        $products = array_values($this->fx->p);

        return $products[$index];
    }

    private function solution(string $title, string $locale, bool $published): Solution
    {
        $solution = new Solution();
        $solution->setPublished($published);
        $translation = $solution->translate($locale);
        $translation->setTitle($title)->setIntro('Вступ');
        $this->em()->persist($solution);

        return $solution;
    }

    /** @param list<string> $pros */
    private function option(Solution $solution, Product $product, int $position, string $locale, array $pros): void
    {
        $option = new SolutionOption(null, $product);
        $option->setPosition($position);
        $translation = $option->translate($locale);
        $translation->setPros($pros)->setNote('Абзац');
        $solution->addOption($option);
    }
}
