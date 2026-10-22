<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog\Url;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Query\CatalogCacheVersion;
use App\Core\Catalog\Url\CatalogSlugs;
use App\Core\Catalog\Url\CategoryPaths;
use App\Core\Catalog\Url\Command\RegenerateSlugsCommand;
use App\Core\Seo\RedirectManager;
use App\Core\Seo\SlugChangeRecorder;
use App\Core\Shared\Text\UkrainianSlugger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * catalog:slugs:regenerate: лише автоматичні старі слаги, без тих, на які посилається контент (сторінки, описи товарів і
 * категорій, зокрема підшляхи категорії); шляхи категорій — за ними; запис лише коли сайт у noindex; пакети за id;
 * старі адреси — 301 на нові (E3).
 */
final class RegenerateSlugsCommandTest extends KernelTestCase
{
    public function testRegeneratesOnlyAutoSlugsNotLinkedFromContent(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $s = bin2hex(random_bytes(2));

        $root = new Category();
        $root->translate('uk')->setName("Військова символіка $s")->setSlug("viys-kova-symvolika-$s"); // старий слагер
        $child = new Category($root);
        $child->translate('uk')->setName('Настільні прапорці')->setSlug('nastil-ni-praportsi');
        $auto = new Product("RS-A-$s", 'UAH');
        $auto->translate('uk')->setName("Запальничка військова $s")->setSlug("zapal-nychka-viys-kova-$s-2");
        $manual = new Product("RS-M-$s", 'UAH');
        $manual->translate('uk')->setName("Мультитул $s")->setSlug("my-tool-$s");
        $linked = new Product("RS-L-$s", 'UAH');
        $linked->translate('uk')->setName("Персональне гравіювання $s")->setSlug("personal-ne-hraviyuvannya-$s");
        $described = new Product("RS-D-$s", 'UAH');
        $described->translate('uk')->setName("Сувенірна монета $s")->setSlug("suvenirna-moneta-$s"); // в обох слагерах однаково
        $described->translate('uk')->setDescription('<p>До пари — <a href="https://shop.example.com/ru/kal-yan-viys-kovyy-'.$s.'?utm=x">кальян</a>, <a href="/zhovtyy-'.$s.'/pidkat">розділ</a></p>');
        $fromDescription = new Product("RS-F-$s", 'UAH');
        $fromDescription->translate('uk')->setName("Кальян військовий $s")->setSlug("kal-yan-viys-kovyy-$s");
        $parent = new Category();
        $parent->translate('uk')->setName("Жовтий $s")->setSlug("zhovtyy-$s"); // на підкатегорію посилається опис
        foreach ([$root, $child, $auto, $manual, $linked, $described, $fromDescription, $parent] as $e) {
            $em->persist($e);
        }
        $em->flush();
        $db->executeStatement('INSERT INTO content_page (published, position, show_in_header, show_in_footer, created_at, updated_at) VALUES (1, 0, 0, 0, NOW(), NOW())');
        $db->executeStatement("INSERT INTO content_page_translation (page_id, locale, title, slug, body) VALUES (?, 'uk', 'Про нас', ?, ?)", [
            (int) $db->lastInsertId(), "pro-$s", '<p>Дивіться <a href="/personal-ne-hraviyuvannya-'.$s.'">гравіювання</a></p>',
        ]);

        $open = $this->tester(new MockResponse('', ['http_code' => 200]));
        $open->execute(['--dry-run' => true]);
        $open->assertCommandIsSuccessful();
        self::assertStringContainsString('буде змінено', $open->getDisplay(), 'сухий прогін можна й на відкритому сайті');
        self::assertSame("zapal-nychka-viys-kova-$s-2", $this->slug($db, 'catalog_product_translation', 'product_id', (int) $auto->getId()), 'сухий прогін нічого не пише');

        self::assertSame(1, $open->execute([]), 'сайт без X-Robots-Tag: noindex — відмова');
        self::assertStringContainsString('не закритий від індексу', $open->getDisplay());
        self::assertSame(1, $this->tester(new MockResponse('', ['error' => 'timeout']))->execute([]), 'головна не відповіла — теж відмова');
        self::assertSame("zapal-nychka-viys-kova-$s-2", $this->slug($db, 'catalog_product_translation', 'product_id', (int) $auto->getId()));

        $tester = $this->tester(new MockResponse('', ['http_code' => 200, 'response_headers' => ['X-Robots-Tag' => 'noindex, nofollow']]));
        $tester->execute(['--batch' => 1]); // пакет з одного перекладу — keyset за id
        $tester->assertCommandIsSuccessful();
        self::assertSame("viiskova-symvolika-$s", $this->slug($db, 'catalog_category_translation', 'category_id', (int) $root->getId()));
        self::assertSame("viiskova-symvolika-$s/nastilni-praportsi", (string) $db->fetchOne("SELECT path FROM catalog_category_translation WHERE category_id = ? AND locale = 'uk'", [$child->getId()]), 'шлях підкатегорії перераховано');
        self::assertSame("zapalnychka-viiskova-$s", $this->slug($db, 'catalog_product_translation', 'product_id', (int) $auto->getId()), 'автоматичний (і з суфіксом -2) — новим слагером');
        self::assertSame("my-tool-$s", $this->slug($db, 'catalog_product_translation', 'product_id', (int) $manual->getId()), 'ручний — не чіпаємо');
        self::assertSame("personal-ne-hraviyuvannya-$s", $this->slug($db, 'catalog_product_translation', 'product_id', (int) $linked->getId()), 'на адресу посилається сторінка — не чіпаємо');
        self::assertStringContainsString('посилання в текстах', $tester->getDisplay());
        self::assertSame("kal-yan-viys-kovyy-$s", $this->slug($db, 'catalog_product_translation', 'product_id', (int) $fromDescription->getId()), 'посилання з опису товару (абсолютне, з мовою й параметрами) — не чіпаємо');
        self::assertSame("zhovtyy-$s", $this->slug($db, 'catalog_category_translation', 'category_id', (int) $parent->getId()), 'посилання на підшлях категорії — батьківську не чіпаємо');

        // E3: старі адреси — 301 на нові (товар — у транзакції пакета, категорія з підкатегорією — з rebuildAll)
        $redirects = self::getContainer()->get(RedirectManager::class);
        self::assertSame("/zapalnychka-viiskova-$s", $redirects->find("/zapal-nychka-viys-kova-$s-2")['target'] ?? null);
        self::assertSame("/viiskova-symvolika-$s/", $redirects->find("/viys-kova-symvolika-$s/")['target'] ?? null);
        self::assertSame("/viiskova-symvolika-$s/nastilni-praportsi/", $redirects->find("/viys-kova-symvolika-$s/nastil-ni-praportsi/")['target'] ?? null);
        self::assertNull($redirects->find("/my-tool-$s"), 'не змінений — без правила');
    }

    private function tester(MockResponse $home): CommandTester
    {
        $c = self::getContainer();
        /** @var list<string> $locales */
        $locales = $c->getParameter('app.locales');
        $command = new RegenerateSlugsCommand(
            $c->get(\Doctrine\DBAL\Connection::class),
            $c->get(UkrainianSlugger::class),
            $c->get(CatalogSlugs::class),
            $c->get(CategoryPaths::class),
            $c->get(CatalogCacheVersion::class),
            $c->get(SlugChangeRecorder::class),
            new MockHttpClient($home),
            'shop.example.com',
            'uk',
            $locales,
        );
        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->addCommand($command);

        return new CommandTester($application->find('catalog:slugs:regenerate'));
    }

    private function slug(\Doctrine\DBAL\Connection $db, string $table, string $owner, int $id): string
    {
        return (string) $db->fetchOne("SELECT slug FROM {$table} WHERE {$owner} = ? AND locale = 'uk'", [$id]);
    }
}
