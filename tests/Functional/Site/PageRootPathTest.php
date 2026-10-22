<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Catalog\Url\CatalogSlugs;
use App\Core\Content\Entity\Page;
use App\Site\Redirect\SiteLiveUrls;
use App\Site\SiteUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Сторінки в корені сайту (app.page_path_prefix: '', типово з 20.10.2026): /{slug}/ і /ru/{slug}/ з кінцевим слешем,
 * сторінка раніше за категорію, категорії й товари як були, стара /page/{slug} — 301, неопублікована — 404; слаг не збігається з
 * категорією, товаром і службовою адресою (валідатор адмінки й CatalogSlugs навпаки); sitemap, hreflang, перевірка живих адрес.
 */
final class PageRootPathTest extends CatalogPageTestCase
{
    public function testPagesLiveAtRootBeforeCategories(): void
    {
        $locales = self::locales();
        $main = self::defaultLocale();
        $other = array_values(array_diff($locales, [$main]))[0];
        // однаковий слаг усіма мовами (B2B-магазин: services / about / contacts)
        $services = $this->page('services-'.$this->suffix, 'Послуги', $locales, published: true);
        $draft = $this->page('draft-'.$this->suffix, 'Чернетка', [$main], published: false);
        // сторінка й коренева категорія з тим самим шляхом (оминаючи валідатор) — виграє сторінка
        $this->fx->bags->translate($main)->setSlug('clash-'.$this->suffix);
        $clash = $this->page('clash-'.$this->suffix, 'Сторінка-конфлікт', [$main], published: true);
        $this->em()->flush();

        $slug = 'services-'.$this->suffix;
        self::assertSame('/'.$slug.'/', $this->url('site_page', ['slug' => $slug, '_locale' => $main]));
        self::assertSame('/'.$other.'/'.$slug.'/', $this->url('site_page', ['slug' => $slug, '_locale' => $other]));
        self::assertStringEndsWith('/'.$slug.'/', (string) static::getContainer()->get(SiteUrlGenerator::class)->page($services, $main));

        foreach ([$main => '/'.$slug.'/', $other => '/'.$other.'/'.$slug.'/'] as $locale => $path) {
            $crawler = $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            self::assertSame($locale.' Послуги', $crawler->filter('h1')->text());
            $hreflang = $crawler->filter('link[rel="alternate"][hreflang]')->each(static fn ($n): string => (string) $n->attr('href'));
            self::assertContains('https://'.static::getContainer()->getParameter('site_host').'/'.$other.'/'.$slug.'/', $hreflang);
            self::assertStringEndsWith($path, (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
        }

        $crawler = $this->client->request('GET', '/clash-'.$this->suffix.'/');
        self::assertResponseIsSuccessful();
        self::assertSame($main.' Сторінка-конфлікт', $crawler->filter('h1')->text(), 'сторінка раніше за категорію');

        // каталог як був: категорія /{шлях}/, товар /{slug}
        $this->client->request('GET', $this->categoryUrl($this->fx->shoes, $main));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $this->productUrl(array_values($this->fx->p)[0], $main));
        self::assertResponseIsSuccessful();

        // стара адреса — 301 на нову тієї ж мови; неіснуюча стара — 404; неопублікована — 404
        $this->client->request('GET', '/page/'.$slug);
        self::assertResponseRedirects('/'.$slug.'/', 301);
        $this->client->request('GET', '/'.$other.'/page/'.$slug);
        self::assertResponseRedirects('/'.$other.'/'.$slug.'/', 301);
        $this->client->request('GET', '/page/no-such-'.$this->suffix);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/draft-'.$this->suffix.'/');
        self::assertResponseStatusCodeSame(404);
        self::assertNull(static::getContainer()->get(SiteUrlGenerator::class)->page($draft, $main));

        $this->client->request('GET', '/sitemap.xml');
        $sitemap = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('/'.$slug.'/</loc>', $sitemap);
        self::assertStringNotContainsString('/page/'.$slug, $sitemap);

        $live = static::getContainer()->get(SiteLiveUrls::class);
        self::assertTrue($live->isLive('/'.$slug.'/'), 'ручний редирект на сторінку в корені — жива адреса');
        self::assertFalse($live->isLive('/draft-'.$this->suffix.'/'));
        self::assertNotNull($clash->getId());
    }

    public function testSlugMayNotClashWithCatalogOrReservedWords(): void
    {
        $main = self::defaultLocale();
        $validator = static::getContainer()->get(ValidatorInterface::class);
        $categoryPath = (string) $this->fx->shoes->getTranslation($main)?->getPath();
        $productSlug = (string) array_values($this->fx->p)[0]->getTranslation($main)?->getSlug();
        $cases = ['cart' => 'content.page_translation.slug.reserved', 'blog' => 'content.page_translation.slug.reserved', 'solutions' => 'content.page_translation.slug.reserved', $categoryPath => 'content.page_translation.slug.category', $productSlug => 'content.page_translation.slug.product', 'free-'.$this->suffix => null];
        foreach ($cases as $slug => $expected) {
            $translation = (new Page())->translate($main);
            $translation->setTitle('X')->setSlug($slug);
            $messages = array_map(static fn ($v): string => $v->getMessageTemplate(), iterator_to_array($validator->validate($translation)));
            null === $expected ? self::assertNotContains('content.page_translation.slug.reserved', $messages, $slug) : self::assertContains($expected, $messages, $slug);
        }

        // навпаки: товар чи коренева категорія не забирають слаг наявної сторінки
        $this->page('about-'.$this->suffix, 'Про нас', [$main], published: true);
        $this->em()->flush();
        $catalog = static::getContainer()->get(CatalogSlugs::class);
        self::assertNotNull($catalog->productConflict('about-'.$this->suffix, $main));
        self::assertNotNull($catalog->categoryConflict('about-'.$this->suffix, $main, null));
        self::assertNull($catalog->categoryConflict('about-'.$this->suffix, $main, $categoryPath), 'підкатегорія — інший шлях');
        self::assertNotSame('about-'.$this->suffix, $catalog->uniqueProductSlug('about-'.$this->suffix, $main));

        // слаг із назви (SlugListener) теж обходить категорію: «Взуття» з тим самим шляхом отримує суфікс
        $auto = new Page();
        $auto->translate($main)->setTitle($categoryPath);
        $this->em()->persist($auto);
        $this->em()->flush();
        self::assertNotSame($categoryPath, $auto->translate($main)->getSlug());
    }

    public function testSmokeChecksPagesAtRoot(): void
    {
        $this->page('smoke-'.$this->suffix, 'Smoke', self::locales(), published: true);
        $this->em()->flush();
        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('store:smoke'));
        $tester->execute([]);
        $display = $tester->getDisplay();
        self::assertSame(0, $tester->getStatusCode(), $display);
        foreach (self::locales() as $locale) {
            self::assertMatchesRegularExpression('#OK\s+site_page '.$locale.'\b.*/smoke-'.$this->suffix.'/#', $display, 'smoke перевіряє сторінку за адресою в корені');
        }
    }

    /** @param list<string> $locales */
    private function page(string $slug, string $title, array $locales, bool $published): Page
    {
        $page = new Page();
        $page->setPublished($published);
        foreach ($locales as $locale) {
            $page->translate($locale)->setTitle($locale.' '.$title)->setSlug($slug)->setBody('<p>'.$title.'</p>');
        }
        $this->em()->persist($page);

        return $page;
    }
}
