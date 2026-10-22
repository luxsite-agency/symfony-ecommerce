<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Content\Entity\Page;
use App\Core\Seo\Enum\RedirectCode;
use App\Core\Seo\RedirectManager;
use Doctrine\DBAL\Connection;

/**
 * Менеджер редиректів на сайті (E3): правило спрацьовує лише на 404, 301/302 з перенесенням query, 410 і 404 —
 * сторінки помилок, без правила — журнал 404; хост адмінки не зачіпається. Авто-301: стара адреса товару, категорії
 * (з дочірніми) і сторінки після зміни слага веде на нову, нова відкривається.
 */
final class RedirectsTest extends CatalogPageTestCase
{
    public function testRulesApplyOnlyToNotFoundUrls(): void
    {
        $s = $this->suffix;
        $redirects = static::getContainer()->get(RedirectManager::class);
        $product = $this->productUrl($this->fx->p['P1'], self::defaultLocale());
        $redirects->save("/old-$s", RedirectCode::Permanent, $product);
        $redirects->save("/ru/temp-$s/", RedirectCode::Temporary, "/ru/elsewhere-$s");
        $redirects->save("/gone-$s", RedirectCode::Gone, null);
        $redirects->save("/known-404-$s", RedirectCode::NotFound, null);
        $db = static::getContainer()->get(Connection::class);
        // правило з живої адреси (записане повз перевірку, як було б до публікації товару) не перекриває сторінку
        $db->insert('seo_redirect', ['source' => $product, 'target' => "/never-$s", 'target_path' => "/never-$s", 'code' => 301, 'kind' => 'manual', 'hits' => 0, 'created_at' => date('Y-m-d H:i:s')]);

        $this->get("/OLD-$s?utm_source=mail");
        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects($product.'?utm_source=mail', 301, 'регістр нормалізовано, query перенесено');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $this->get("/ru/temp-$s/");
        self::assertResponseRedirects("/ru/elsewhere-$s", 302);

        $this->get("/gone-$s");
        self::assertResponseStatusCodeSame(410);

        $this->get("/known-404-$s");
        self::assertResponseStatusCodeSame(404);

        $this->get($product);
        self::assertResponseIsSuccessful('живий товар не переадресовується');

        $this->client->request('POST', "/old-$s");
        self::assertNotSame(301, $this->client->getResponse()->getStatusCode(), 'лише GET/HEAD');

        self::assertSame(1, (int) $db->fetchOne('SELECT hits FROM seo_redirect WHERE source = ?', ["/old-$s"]), 'POST не рахується');
        self::assertNotFalse($db->fetchOne('SELECT last_hit_at FROM seo_redirect WHERE source = ?', ["/gone-$s"]));
        self::assertFalse($db->fetchOne('SELECT 1 FROM seo_not_found WHERE path IN (?, ?)', ["/gone-$s", "/known-404-$s"]), 'адреси з правилом — не в журналі');

        // адмінка: та сама адреса на admin_host — без редиректу
        /** @var string $adminHost */
        $adminHost = static::getContainer()->getParameter('admin_host');
        $this->client->request('GET', 'https://'.$adminHost."/old-$s");
        self::assertNotSame(301, $this->client->getResponse()->getStatusCode());
    }

    public function testWrongCaseOfLiveUrlRedirectsWithoutLogging(): void
    {
        $product = $this->productUrl($this->fx->p['P1'], self::defaultLocale());
        $category = $this->categoryUrl($this->fx->running, self::defaultLocale());
        $this->get(strtoupper($product).'?utm=1');
        self::assertResponseRedirects($product.'?utm=1', 301);
        $this->get('http://localhost'.str_replace('/', '//', strtoupper($category))); // повний URL: «//x» BrowserKit прочитав би як хост
        self::assertResponseRedirects($category, 301, 'подвійні слеші й регістр');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertFalse(static::getContainer()->get(Connection::class)->fetchOne('SELECT 1 FROM seo_not_found WHERE path IN (?, ?)', [$product, $category]), 'живі адреси — не в журналі');

        $this->get('/No-Such-'.$this->suffix);
        self::assertResponseStatusCodeSame(404, 'мертва адреса в іншому регістрі — 404 і журнал');
        self::assertNotFalse(static::getContainer()->get(Connection::class)->fetchOne('SELECT 1 FROM seo_not_found WHERE path = ?', ['/no-such-'.$this->suffix]));
    }

    public function testUnknownUrlsGoToNotFoundLog(): void
    {
        $s = $this->suffix;
        $this->client->request('GET', "/Missing-$s/?email=a@b.c", server: ['HTTP_REFERER' => 'https://www.google.com/url?q=secret']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', "/missing-$s/", server: ['HTTP_REFERER' => '']); // BrowserKit інакше підставить попередню адресу
        $row = static::getContainer()->get(Connection::class)->fetchAssociative('SELECT hits, referer FROM seo_not_found WHERE path = ?', ["/missing-$s/"]);
        self::assertNotFalse($row);
        self::assertSame(2, (int) $row['hits']);
        self::assertSame('https://www.google.com/url', $row['referer']);
    }

    public function testProductSlugChangeRedirectsOldUrl(): void
    {
        // усі зміни — до першого запиту: клієнт перезавантажує ядро, сутності фікстури після цього не в новому EM
        $old = [];
        foreach (self::locales() as $locale) {
            $old[$locale] = $this->productUrl($this->fx->p['P2'], $locale);
            $this->fx->p['P2']->translate($locale)->setSlug($locale.'-p2-renamed-'.$this->suffix);
        }
        $this->em()->flush();
        foreach (self::locales() as $locale) {
            $new = $this->productUrl($this->fx->p['P2'], $locale);
            self::assertNotSame($old[$locale], $new);

            $this->get($old[$locale]);
            self::assertResponseRedirects($new, 301, $locale);
            $this->get($new);
            self::assertResponseIsSuccessful($locale);
        }
    }

    public function testCategoryPathChangeRedirectsCategoryAndChildren(): void
    {
        $locale = self::defaultLocale();
        $oldChild = $this->categoryUrl($this->fx->running, $locale);
        $oldRoot = $this->categoryUrl($this->fx->shoes, $locale);
        $this->fx->shoes->translate($locale)->setSlug('vzuttia-'.$this->suffix);
        $this->em()->flush();
        $this->reindex($this->fx->productIds());

        $this->get($oldRoot);
        self::assertResponseRedirects($this->categoryUrl($this->fx->shoes, $locale), 301);
        $this->get($oldChild);
        self::assertResponseRedirects($this->categoryUrl($this->fx->running, $locale), 301, 'дочірня категорія — каскадом');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testPageSlugChangeRedirectsOldUrl(): void
    {
        $page = new Page();
        $page->setPublished(true);
        $page->translate('uk')->setTitle('Доставка '.$this->suffix);
        $this->em()->persist($page);
        $this->em()->flush();
        $old = (string) $page->getTranslation('uk')?->getSlug();
        $page->translate('uk')->setSlug('dostavka-i-oplata-'.$this->suffix);
        $this->em()->flush();

        $this->get('/'.$old.'/');
        self::assertResponseRedirects('/dostavka-i-oplata-'.$this->suffix.'/', 301);
        // стара адреса з префіксом /page — теж на нову (через 301 /page/{slug} → /{slug}/ і запис про зміну слага)
        $this->get('/page/'.$old);
        self::assertResponseRedirects('/dostavka-i-oplata-'.$this->suffix.'/', 301);
    }
}
