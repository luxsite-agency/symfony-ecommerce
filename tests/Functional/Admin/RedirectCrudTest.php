<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Security\Enum\UserRole;
use App\Core\Seo\Enum\RedirectCode;
use App\Core\Seo\NotFoundLog;
use App\Core\Seo\RedirectManager;
use App\Tests\Integration\Core\Catalog\FilterIndex\CatalogIndexFixture;
use Doctrine\DBAL\Connection;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;

/** Адмінка менеджера редиректів (E3): форма з нормалізацією й відмовою на цикл, журнал 404 → «Створити редирект», CSV. */
final class RedirectCrudTest extends AdminWebTestCase
{
    public function testFormNormalizesSourceMergesChainsAndRejectsCycles(): void
    {
        $this->loginAs(UserRole::Manager);
        $s = bin2hex(random_bytes(3));
        foreach (['/redirect', '/redirect/new', '/redirect/import', '/not-found'] as $path) {
            $this->client->request('GET', $this->adminUrl($path));
            self::assertResponseIsSuccessful($path);
        }
        static::getContainer()->get(RedirectManager::class)->save("/b-$s", RedirectCode::Permanent, "/c-$s");

        $crawler = $this->submit($this->client->request('GET', $this->adminUrl('/redirect/new')), [
            'Redirect[source]' => "https://old.example/A-$s?utm=1",
            'Redirect[code]' => '301',
            'Redirect[target]' => "/b-$s",
        ]);
        self::assertFormRedirected($crawler);
        $db = static::getContainer()->get(Connection::class);
        self::assertSame("/c-$s", $db->fetchOne('SELECT target FROM seo_redirect WHERE source = ?', ["/a-$s"]), 'джерело нормалізовано, ціль — кінцева');

        $crawler = $this->submit($this->client->request('GET', $this->adminUrl('/redirect/new')), [
            'Redirect[source]' => "/c-$s",
            'Redirect[code]' => '301',
            'Redirect[target]' => "/a-$s",
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Цикл', $crawler->filter('.invalid-feedback')->text(''));

        $crawler = $this->submit($this->client->request('GET', $this->adminUrl('/redirect/new')), [
            'Redirect[source]' => "/d-$s",
            'Redirect[code]' => '410',
            'Redirect[target]' => 'буде відкинуто',
        ]);
        self::assertFormRedirected($crawler);
        self::assertNull($db->fetchOne('SELECT target FROM seo_redirect WHERE source = ?', ["/d-$s"]), '410 — без цілі');
    }

    /** Рев'ю #37, п. 1: у POST адмінки (admin_host) перевірка живої адреси мусить матчити сайт як GET на site_host. */
    public function testLiveProductAndCategoryUrlsAreRejectedOnAdminPost(): void
    {
        $this->loginAs(UserRole::Manager);
        $s = bin2hex(random_bytes(3));
        $fx = new CatalogIndexFixture($this->em(), $s);
        $product = '/'.$fx->p['P1']->getTranslation('uk')?->getSlug();
        $category = '/'.$fx->running->getTranslation('uk')?->getPath().'/';
        static::getContainer()->get(RedirectManager::class)->recordMoves([["/was-$s", $product]]); // авто-301 на живий товар

        foreach ([$product, $category] as $live) {
            $crawler = $this->submit($this->client->request('GET', $this->adminUrl('/redirect/new')), [
                'Redirect[source]' => $live,
                'Redirect[code]' => '301',
                'Redirect[target]' => "/inshe-$s",
            ]);
            self::assertResponseStatusCodeSame(422, $live);
            self::assertStringContainsString('відкривається на сайті', $crawler->filter('.invalid-feedback')->text(''), $live);
        }
        self::assertSame($product, static::getContainer()->get(RedirectManager::class)->find("/was-$s")['target'] ?? null, 'авто-301 не переписано');

        // той самий захист в імпорті CSV з адмінки
        $file = tempnam(sys_get_temp_dir(), 'csv').'.csv';
        file_put_contents($file, "$product;301;/inshe-$s\n$category;410;\n");
        $form = $this->client->request('GET', $this->adminUrl('/redirect/import'))->filter('form[name="form"]')->form();
        $field = $form['form[file]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($file);
        $crawler = $this->client->submit($form);
        unlink($file);
        self::assertSame('2', $crawler->filter('[data-errors]')->text());
        self::assertSame('0', $crawler->filter('[data-created]')->text());
    }

    public function testManagerCannotRedirectToOtherSites(): void
    {
        $this->loginAs(UserRole::Manager);
        $s = bin2hex(random_bytes(3));
        $crawler = $this->submit($this->client->request('GET', $this->adminUrl('/redirect/new')), [
            'Redirect[source]' => "/out-$s",
            'Redirect[code]' => '301',
            'Redirect[target]' => 'https://evil.example/',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('адміністратор', $crawler->filter('.invalid-feedback')->text(''));

        $host = (string) static::getContainer()->getParameter('site_host');
        self::assertFormRedirected($this->submit($this->client->request('GET', $this->adminUrl('/redirect/new')), [
            'Redirect[source]' => "/out-$s",
            'Redirect[code]' => '301',
            'Redirect[target]' => "https://$host/in-$s",
        ]));
        self::assertSame("/in-$s", static::getContainer()->get(RedirectManager::class)->find("/out-$s")['target'] ?? null, 'свій хост — шлях');
    }

    public function testCreateRedirectFromNotFoundLog(): void
    {
        $this->loginAs(UserRole::Manager);
        $s = bin2hex(random_bytes(3));
        static::getContainer()->get(NotFoundLog::class)->record("/lost-$s", null);
        $id = (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT id FROM seo_not_found WHERE path = ?', ["/lost-$s"]);

        $crawler = $this->client->request('GET', $this->adminUrl('/not-found'));
        self::assertGreaterThan(0, $crawler->filter('a:contains("Створити редирект")')->count());
        $this->client->request('GET', $this->adminUrl("/not-found/$id/create-redirect"));
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSame("/lost-$s", $crawler->filter('input[name="Redirect[source]"]')->attr('value'));

        self::assertFormRedirected($this->submit($crawler, ['Redirect[code]' => '301', 'Redirect[target]' => "/found-$s"]));
        self::assertFalse(static::getContainer()->get(Connection::class)->fetchOne('SELECT 1 FROM seo_not_found WHERE path = ?', ["/lost-$s"]), 'з журналу знято');

        // очищення журналу — лише POST з CSRF
        static::getContainer()->get(NotFoundLog::class)->record("/another-$s", null);
        $crawler = $this->client->request('GET', $this->adminUrl('/not-found/clear'));
        self::assertNotFalse(static::getContainer()->get(Connection::class)->fetchOne('SELECT 1 FROM seo_not_found WHERE path = ?', ["/another-$s"]), 'GET нічого не видаляє');
        $this->client->submit($crawler->filter('form:has(input[name="token"])')->form());
        self::assertResponseRedirects();
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM seo_not_found'));
    }

    public function testCsvImportShowsReportAndExportDownloads(): void
    {
        $this->loginAs(UserRole::Manager);
        $s = bin2hex(random_bytes(3));
        $file = tempnam(sys_get_temp_dir(), 'csv').'.csv';
        file_put_contents($file, "source;code;target\n/i1-$s;301;/i2-$s\n/i3-$s;301;\n/i4-$s;410;\n");

        $crawler = $this->client->request('GET', $this->adminUrl('/redirect/import'));
        $form = $crawler->filter('form[name="form"]')->form();
        $field = $form['form[file]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($file);
        $crawler = $this->client->submit($form);
        unlink($file);
        self::assertResponseIsSuccessful();
        self::assertSame('2', $crawler->filter('[data-created]')->text());
        self::assertSame('1', $crawler->filter('[data-errors]')->text());
        self::assertSame(1, $crawler->filter('[data-error-line="3"]')->count());

        $this->client->request('GET', $this->adminUrl('/redirect/export'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString("/i1-$s;301;/i2-$s;manual;0;", (string) $this->client->getInternalResponse()->getContent());
    }

    /** @param array<string, string> $values */
    private function submit(Crawler $crawler, array $values): Crawler
    {
        $form = $crawler->filter('form[name="Redirect"]')->form();
        $form->setValues($values);
        $payload = $form->getPhpValues();
        $payload['ea']['newForm']['btn'] = 'saveAndReturn';

        return $this->client->request($form->getMethod(), $form->getUri(), $payload);
    }
}
