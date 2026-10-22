<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Content\Entity\Page;
use App\Site\Redirect\SiteLiveUrls;
use App\Tests\PagePrefixKernel;

/**
 * Сторінки з префіксом (app.page_path_prefix: '/info', ядро PagePrefixKernel): /info/{slug} і /ru/info/{slug}, у sitemap — ці адреси;
 * стара /page/{slug} — 301 на чинну адресу і з префіксом (посилання в пошуку), /{slug}/ — не сторінка.
 */
final class PagePrefixPathTest extends CatalogPageTestCase
{
    protected static function getKernelClass(): string
    {
        return PagePrefixKernel::class;
    }

    public function testPagesLiveUnderPrefixAndOldPageUrlsRedirect(): void
    {
        $main = self::defaultLocale();
        $other = array_values(array_diff(self::locales(), [$main]))[0];
        $slug = 'umovy-'.$this->suffix;
        $page = new Page();
        $page->setPublished(true);
        foreach ([$main, $other] as $locale) {
            $page->translate($locale)->setTitle($locale.' Умови')->setSlug($slug)->setBody('<p>Умови</p>');
        }
        $this->em()->persist($page);
        $this->em()->flush();

        $p = PagePrefixKernel::PREFIX;
        self::assertSame($p.'/'.$slug, $this->url('site_page', ['slug' => $slug, '_locale' => $main]));
        foreach ([$main => $p.'/'.$slug, $other => '/'.$other.$p.'/'.$slug] as $locale => $path) {
            $crawler = $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            self::assertSame($locale.' Умови', $crawler->filter('h1')->text());
        }

        $this->client->request('GET', '/page/'.$slug);
        self::assertResponseRedirects($p.'/'.$slug, 301);
        $this->client->request('GET', '/'.$other.'/page/'.$slug);
        self::assertResponseRedirects('/'.$other.$p.'/'.$slug, 301);
        $this->client->request('GET', '/page/no-such-'.$this->suffix);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/'.$slug.'/');
        self::assertResponseStatusCodeSame(404, 'у режимі з префіксом корінь — лише каталог');

        $this->client->request('GET', '/sitemap.xml');
        self::assertStringContainsString($p.'/'.$slug.'</loc>', (string) $this->client->getResponse()->getContent());
        self::assertTrue(static::getContainer()->get(SiteLiveUrls::class)->isLive($p.'/'.$slug));
    }
}
