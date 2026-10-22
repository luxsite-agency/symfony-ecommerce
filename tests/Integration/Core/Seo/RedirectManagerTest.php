<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Seo;

use App\Core\Seo\Enum\RedirectCode;
use App\Core\Seo\Enum\RedirectKind;
use App\Core\Seo\Exception\InvalidRedirect;
use App\Core\Seo\NotFoundLog;
use App\Core\Seo\RedirectCsv;
use App\Core\Seo\RedirectManager;
use App\Core\Seo\SlugChange;
use App\Core\Seo\SlugChangeRecorder;
use App\Tests\Integration\Core\Catalog\FilterIndex\CatalogIndexFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Менеджер редиректів (E3): злиття ланцюгів, заборона циклів і редиректу на себе, авто-301 (пакет змін, повернення
 * назад, обмін адресами, каскад шляхів категорій), імпорт CSV зі звітом, журнал 404 з лімітом.
 */
final class RedirectManagerTest extends KernelTestCase
{
    private RedirectManager $redirects;
    private Connection $db;
    private string $s;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->redirects = self::getContainer()->get(RedirectManager::class);
        $this->db = self::getContainer()->get(Connection::class);
        $this->s = bin2hex(random_bytes(3));
    }

    public function testChainsAreMergedBothWays(): void
    {
        [$a, $b, $c, $d] = $this->paths('a', 'b', 'c', 'd');
        $this->redirects->save($a, RedirectCode::Permanent, $b);
        $this->redirects->save($b, RedirectCode::Permanent, $c); // A→B, B→C ⇒ A→C, B→C
        self::assertSame($c, $this->target($a));
        self::assertSame($c, $this->target($b));

        $this->redirects->save($d, RedirectCode::Temporary, $a); // D→A, а A→C ⇒ D→C (код D свій)
        self::assertSame($c, $this->target($d));
        self::assertSame(RedirectCode::Temporary, $this->redirects->find($d)['code'] ?? null);
    }

    public function testSelfRedirectAndCyclesAreRejected(): void
    {
        [$a, $b, $c] = $this->paths('a', 'b', 'c');
        $this->assertInvalid('ту саму адресу', fn () => $this->redirects->save($a, RedirectCode::Permanent, strtoupper($a).'?')); // нормалізація: той самий шлях
        $this->redirects->save($a, RedirectCode::Permanent, $b);
        $this->redirects->save($b, RedirectCode::Permanent, $c);
        $this->assertInvalid('Цикл', fn () => $this->redirects->save($c, RedirectCode::Permanent, $a));
        $this->assertInvalid('Цикл', fn () => $this->redirects->save($c, RedirectCode::Permanent, $b));
        self::assertNull($this->redirects->find($c), 'відхилене правило не записано');
        self::assertNull($this->redirects->check($c, RedirectCode::Gone, null), '410 циклів не має');
        $this->assertInvalid('вкажіть ціль', fn () => $this->redirects->save($c, RedirectCode::Permanent, ''));
        $this->assertInvalid('Невалідна ціль', fn () => $this->redirects->save($c, RedirectCode::Permanent, 'relative'));
    }

    public function testLiveUrlsCannotBeManualSourcesAndNewEntitiesReleaseTheirUrl(): void
    {
        $s = $this->s;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fx = new CatalogIndexFixture($em, $s);
        $p1 = '/'.$fx->p['P1']->getTranslation('uk')?->getSlug();
        $this->assertInvalid('відкривається на сайті', fn () => $this->redirects->save($p1, RedirectCode::Permanent, "/x-$s"));
        $this->assertInvalid('відкривається на сайті', fn () => $this->redirects->save("/shoes-$s/", RedirectCode::Gone, null));
        $this->assertInvalid('відкривається на сайті', fn () => $this->redirects->save('/cart/', RedirectCode::Permanent, "/x-$s"));
        $this->redirects->save("/shoes-$s", RedirectCode::Permanent, "/shoes-$s/"); // без слеша — не категорія, 404

        $fx->p['P1']->setActive(false); // вимкнений товар віддає 404 — 410 вручну можна
        $em->flush();
        self::assertTrue($this->redirects->save($p1, RedirectCode::Gone, null));

        // нова адреса збіглась зі старим правилом — правило знято
        $this->redirects->save("/fresh-$s", RedirectCode::Permanent, "/x-$s");
        $fx->p['P2']->translate('ru')->setName('Новий')->setSlug("fresh-$s");
        $em->flush();
        self::assertNotNull($this->redirects->find("/fresh-$s"), 'ru-слаг дає /ru/fresh-…, не /fresh-…');
        $fx->p['P3']->translate('uk')->setSlug("fresh-$s");
        $em->flush();
        self::assertNull($this->redirects->find("/fresh-$s"));
    }

    public function testOwnHostAbsoluteTargetsAreLocalAndCannotHideCycles(): void
    {
        [$a, $b] = $this->paths('a', 'b');
        $host = (string) self::getContainer()->getParameter('site_host');
        $this->redirects->save($a, RedirectCode::Permanent, 'https://'.$host.$b);
        self::assertSame($b, $this->target($a), 'свій хост — шлях');
        $this->assertInvalid('Цикл', fn () => $this->redirects->save($b, RedirectCode::Permanent, 'https://www.'.$host.$a));
        $this->assertInvalid('адміністратор', fn () => $this->redirects->save($b, RedirectCode::Permanent, 'https://other.example/x', allowExternal: false));
        self::assertTrue($this->redirects->save($b, RedirectCode::Permanent, 'https://'.$host.'/x-'.$this->s, allowExternal: false), 'свій хост менеджеру можна');
    }

    public function testTargetsWithQueryOrOtherSitesAreTerminal(): void
    {
        [$a, $b] = $this->paths('a', 'b');
        $this->redirects->save($a, RedirectCode::Permanent, 'https://other.example'.$b);
        self::assertSame('https://other.example'.$b, $this->target($a));
        $this->redirects->save($b, RedirectCode::Permanent, $a.'?from=b');
        self::assertSame($a.'?from=b', $this->target($b), 'ціль з query — не джерело, не зливається');
    }

    public function testRecordMovesKeepsNoRuleForLiveUrls(): void
    {
        [$a, $b, $c, $x] = $this->paths('a', 'b', 'c', 'x');
        $this->redirects->save($x, RedirectCode::Permanent, $a); // стороннє правило на A
        self::assertSame(1, $this->redirects->recordMoves([[$a, $b]]));
        self::assertSame($b, $this->target($a));
        self::assertSame(RedirectKind::Auto->value, $this->db->fetchOne('SELECT kind FROM seo_redirect WHERE source = ?', [$a]));
        self::assertSame($b, $this->target($x), 'X→A ⇒ X→B');

        $this->redirects->recordMoves([[$b, $c]]); // A→B, B→C ⇒ A→C
        self::assertSame($c, $this->target($a));
        self::assertSame($c, $this->target($b));
        self::assertSame($c, $this->target($x));

        $this->redirects->recordMoves([[$c, $a]]); // повернули стару адресу: A жива — правила з A нема, B→A, C→A
        self::assertNull($this->redirects->find($a));
        self::assertSame($a, $this->target($b));
        self::assertSame($a, $this->target($c));
        self::assertSame($a, $this->target($x));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM seo_redirect WHERE source = target'), 'жодного правила «на себе»');
    }

    public function testSwapInOneBatchCreatesNoRules(): void
    {
        [$a, $b] = $this->paths('a', 'b');
        self::assertSame(0, $this->redirects->recordMoves([[$a, $b], [$b, $a]]));
        self::assertNull($this->redirects->find($a));
        self::assertNull($this->redirects->find($b));
    }

    public function testRecorderBuildsLocalizedUrls(): void
    {
        $s = $this->s;
        $recorder = self::getContainer()->get(SlugChangeRecorder::class);
        $recorder->record([
            new SlugChange(SlugChange::PRODUCT, 'uk', "old-$s", "new-$s"),
            new SlugChange(SlugChange::PRODUCT, 'ru', "old-$s", "new-$s"),
            new SlugChange(SlugChange::CATEGORY, 'uk', "odyah-$s/old", "odyah-$s/new"),
            new SlugChange(SlugChange::PAGE, 'ru', "pro-$s", "o-nas-$s"),
            new SlugChange(SlugChange::POST, 'uk', "novyna-$s", "news-$s"),
            new SlugChange(SlugChange::PRODUCT, 'xx', "old-$s", "new-$s"), // мова поза app.locales — пропуск
        ]);
        self::assertSame("/new-$s", $this->target("/old-$s"));
        self::assertSame("/ru/new-$s", $this->target("/ru/old-$s"));
        self::assertSame("/odyah-$s/new/", $this->target("/odyah-$s/old/"));
        self::assertSame("/ru/o-nas-$s/", $this->target("/ru/pro-$s/"));
        self::assertSame("/blog/news-$s", $this->target("/blog/novyna-$s"));
        self::assertNull($this->redirects->find("/xx/old-$s"));
    }

    public function testCategoryPathChangeCascadesToDescendantsInEveryLocale(): void
    {
        $s = $this->s;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fx = new CatalogIndexFixture($em, $s);
        $fx->shoes->translate('ru')->setName('Обувь')->setSlug("obuv-$s");
        $fx->sneakers->translate('ru')->setName('Кроссовки')->setSlug("krossovki-$s");
        $fx->running->translate('ru')->setName('Бег')->setSlug("beg-$s");
        $em->flush();

        $fx->shoes->translate('uk')->setSlug("vzuttia-$s");
        $fx->shoes->translate('ru')->setSlug("obuv-new-$s");
        $em->flush(); // сам вузол — одразу, нащадки — RebuildCategoryPaths (у тестах sync)

        self::assertSame("/vzuttia-$s/", $this->target("/shoes-$s/"));
        self::assertSame("/vzuttia-$s/sneakers-$s/running-$s/", $this->target("/shoes-$s/sneakers-$s/running-$s/"), 'онука — теж');
        self::assertSame("/ru/obuv-new-$s/krossovki-$s/", $this->target("/ru/obuv-$s/krossovki-$s/"), 'не-дефолтна мова — з префіксом');

        // перенесення гілки під інший корінь
        $fx->sneakers->setParent($fx->bags);
        $em->flush();
        self::assertSame("/bags-$s/sneakers-$s/running-$s/", $this->target("/vzuttia-$s/sneakers-$s/running-$s/"));
        self::assertSame("/bags-$s/sneakers-$s/running-$s/", $this->target("/shoes-$s/sneakers-$s/running-$s/"), 'старіший ланцюг злито');
    }

    public function testProductSlugChangeThroughOrmWritesRedirect(): void
    {
        $s = $this->s;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fx = new CatalogIndexFixture($em, $s);
        $p1 = $fx->p['P1'];
        $old = (string) $p1->getTranslation('uk')?->getSlug();
        $p1->translate('uk')->setSlug("p1-renamed-$s");
        $em->flush();
        self::assertSame("/p1-renamed-$s", $this->target('/'.$old));

        $p1->translate('uk')->setName('Без зміни слага');
        $em->flush();
        self::assertSame("/p1-renamed-$s", $this->target('/'.$old), 'інші поля правил не пишуть');
    }

    public function testCsvImportReportsErrorsAndKeepsValidRows(): void
    {
        $s = $this->s;
        $this->redirects->save("/existing-$s", RedirectCode::Permanent, "/old-target-$s");
        $file = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($file, "\xEF\xBB\xBFsource;code;target\n"
            ."/a-$s;301;/b-$s\n"         // 2: нове
            ."/c-$s;410;\n"              // 3: 410 без цілі
            ."/existing-$s;302;/z-$s\n"  // 4: заміна
            ."/b-$s;301;/a-$s\n"         // 5: цикл з рядком 2
            ."/d-$s;999;/x\n"            // 6: код
            ."/e-$s;301;\n"              // 7: нема цілі
            ."\n"
            ."relative;301;/x-$s\n"      // 9: ок — шлях без слеша нормалізується
            .";301;/y\n"                 // 10: нема джерела
            ."\"/q-$s\";\"301\";\"https://other.example/q\";extra\n"); // 11: лапки й зайва колонка
        $report = self::getContainer()->get(RedirectCsv::class)->import($file);
        unlink($file);

        self::assertSame(4, $report['created'], json_encode($report, \JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(1, $report['updated']);
        self::assertSame([5, 6, 7, 10], array_column($report['errors'], 'line'));
        self::assertStringContainsString('Цикл', $report['errors'][0]['message']);
        self::assertSame("/z-$s", $this->target("/existing-$s"));
        self::assertSame(RedirectCode::Gone, $this->redirects->find("/c-$s")['code'] ?? null);
        self::assertSame('/x-'.$s, $this->target('/relative'));
        self::assertSame('https://other.example/q', $this->target("/q-$s"));

        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::getContainer()->get(RedirectCsv::class)->export($out);
        rewind($out);
        $csv = (string) stream_get_contents($out);
        self::assertStringStartsWith("\xEF\xBB\xBFsource;code;target;kind;hits;last_hit_at\n", $csv);
        self::assertStringContainsString("/a-$s;301;/b-$s;manual;0;\n", $csv);
    }

    public function testNotFoundLogAggregatesWithoutPersonalDataAndPrunes(): void
    {
        $s = $this->s;
        $this->db->executeStatement('DELETE FROM seo_not_found');
        // фіксований час і без вибіркового prune() у record(): раніше 1 з 100 нових шляхів прибирав журнал посеред тесту
        $clock = new MockClock('2026-09-01 12:00:00');
        $log = new NotFoundLog($this->db, 3, 30, $clock, 0);
        foreach (['/wp-login.php', '/.env', '/admin/config.php', '/.git/config', '/xmlrpc.php', '/wp-content/x.js'] as $probe) {
            $log->record($probe, null);
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM seo_not_found'), 'сканування ботів не журналюються');
        $log->record("/gone-$s", 'https://google.com/search?q=my+phone+0501234567');
        $log->record("/gone-$s", null);
        $log->record("/gone-$s", 'not a url');
        $row = $this->db->fetchAssociative('SELECT hits, referer FROM seo_not_found WHERE path = ?', ["/gone-$s"]);
        self::assertSame(['hits' => 3, 'referer' => 'https://google.com/search'], ['hits' => (int) ($row['hits'] ?? 0), 'referer' => $row['referer'] ?? null], 'query referer відкинуто, порожній не затирає');

        $clock->modify('+40 days'); // /gone — 40 днів тому, далеко від межі 30
        foreach (['a', 'b', 'c', 'd'] as $p) {
            $clock->sleep(60); // різний last_hit_at, /a — найдавніший
            $log->record("/$p-$s", null);
        }
        self::assertSame(2, $log->prune(), 'старіше за 30 днів і понад 3 рядки');
        self::assertSame(["/b-$s", "/c-$s", "/d-$s"], $this->db->fetchFirstColumn('SELECT path FROM seo_not_found ORDER BY path'));

        $this->redirects->save("/c-$s", RedirectCode::Gone, null);
        self::assertSame(["/b-$s", "/d-$s"], $this->db->fetchFirstColumn('SELECT path FROM seo_not_found ORDER BY path'), 'правило знімає адресу з журналу');
    }

    /** @return list<string> */
    private function paths(string ...$names): array
    {
        return array_map(fn (string $n): string => "/$n-{$this->s}", $names);
    }

    private function target(string $source): ?string
    {
        return $this->redirects->find($source)['target'] ?? null;
    }

    private function assertInvalid(string $message, callable $save): void
    {
        try {
            $save();
            self::fail('Очікувалась відмова: '.$message);
        } catch (InvalidRedirect $e) {
            self::assertStringContainsStringIgnoringCase($message, $e->getMessage());
        }
    }
}
