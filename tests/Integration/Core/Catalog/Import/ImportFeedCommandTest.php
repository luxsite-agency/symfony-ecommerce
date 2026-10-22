<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog\Import;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** catalog:import:feed --sync на YML і CSV фікстурах: створення карток, помилки у звіті, повтор без змін. */
final class ImportFeedCommandTest extends KernelTestCase
{
    public function testYmlThenCsvSameSourceReconcile(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('catalog:import:feed'));
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $tester->execute(['feed' => __DIR__.'/../../../../Fixtures/feeds/doors.yml', '--source' => 'doors', '--sync' => true]);
        $tester->assertCommandIsSuccessful();
        $out = $tester->getDisplay();
        self::assertStringContainsString('created       2', $out);
        self::assertStringContainsString('invalid       1', $out);
        self::assertStringContainsString('3001', $out, 'Зламаний offer у списку помилок');
        self::assertSame(3, $em->getRepository(ProductVariant::class)->count([]));
        self::assertSame('Двері / Міжкімнатні', $em->getRepository(ProductVariant::class)->findOneBy(['sku' => 'ML-14-BLACK'])?->getProduct()->getMainCategory()?->getPathLabel());
        self::assertSame(3, $em->getRepository(Category::class)->count([]));

        // Той самий каталог у CSV: картки ті ж (externalId ML14 і 2001) → без створення; ru-назва додалась → updated
        $tester->execute(['feed' => __DIR__.'/../../../../Fixtures/feeds/doors.csv', '--source' => 'doors', '--sync' => true]);
        $tester->assertCommandIsSuccessful();
        $out = $tester->getDisplay();
        self::assertStringContainsString('created       0', $out);
        self::assertStringContainsString('updated       2', $out);
        $em->clear();
        self::assertSame(3, $em->getRepository(ProductVariant::class)->count([]), 'Дублів варіантів нема');
        self::assertSame('Двери ML 14', $em->getRepository(ProductVariant::class)->findOneBy(['sku' => 'ML-14-WHITE'])?->getProduct()->translate('ru')->getName());
        self::assertSame(3, $em->getRepository(Category::class)->count([]), 'Категорії за шляхом не продублювались');
    }

    /** Збережений XSS із фіду (консиліум безпеки 10.10 п. 2): опис у БД — лише білий список, і з YML, і з CSV. */
    public function testSupplierHtmlIsSanitizedBeforeSaving(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('catalog:import:feed'));
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $tester->execute(['feed' => __DIR__.'/../../../../Fixtures/feeds/xss.yml', '--source' => 'xss', '--sync' => true]);
        $tester->assertCommandIsSuccessful();
        $description = (string) $em->getRepository(ProductVariant::class)->findOneBy(['sku' => 'XSS-1'])?->getProduct()->translate('uk')->getDescription();
        self::assertStringContainsString('<p>Полотно <strong>МДФ</strong></p>', $description, 'дозволені теги лишаються');
        self::assertStringContainsString('href="https://example.com/doc"', $description);
        self::assertStringContainsString('Висота 2000', $description);
        self::assertStringNotContainsString('<script', $description);
        self::assertStringNotContainsString('alert(', $description, 'script знято разом із вмістом');
        self::assertStringNotContainsString('onerror', $description);
        self::assertStringNotContainsString('onclick', $description);
        self::assertStringNotContainsString('style=', $description);
        self::assertStringNotContainsString('javascript:', $description);

        $tester->execute(['feed' => __DIR__.'/../../../../Fixtures/feeds/xss.csv', '--source' => 'xss_csv', '--sync' => true]);
        $tester->assertCommandIsSuccessful();
        $em->clear();
        $description = (string) $em->getRepository(ProductVariant::class)->findOneBy(['sku' => 'XSS-2'])?->getProduct()->translate('uk')->getDescription();
        self::assertStringContainsString('<p>Полотно <em>МДФ</em></p>', $description);
        self::assertStringNotContainsString('<script', $description);
        self::assertStringNotContainsString('<iframe', $description);
        self::assertStringNotContainsString('evil.example', $description, 'iframe знято разом із src');
        self::assertStringNotContainsString('javascript:', $description);
    }

    /** Опис довший за max_input_length санітайзера (1 МБ) обрізається — не мовчки, а з попередженням у звіті запуску. */
    public function testOversizedDescriptionIsReportedAsWarning(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('catalog:import:feed'));
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $path = tempnam(sys_get_temp_dir(), 'feed-test-').'.csv';
        $description = '<p>'.str_repeat('Довгий опис товару. ', 60_000).'</p>'; // ≈ 1,1 МБ у UTF-8
        file_put_contents($path, "product_id;external_id;sku;name_uk;price;currency;stock;category;description_uk\nBIG;9301;BIG-1;Довгий;10,00;UAH;1;Двері;\"".$description."\"\n");
        try {
            $tester->execute(['feed' => $path, '--source' => 'big', '--sync' => true, '--format' => 'csv']);
        } finally {
            @unlink($path);
        }
        $tester->assertCommandIsSuccessful();
        $out = $tester->getDisplay();
        self::assertStringContainsString('created       1', $out, 'картку не відхилено');
        self::assertStringContainsString('обрізано санітайзером', $out);
        self::assertStringContainsString('BIG', $out);
        $saved = (string) $em->getRepository(ProductVariant::class)->findOneBy(['sku' => 'BIG-1'])?->getProduct()->translate('uk')->getDescription();
        self::assertStringStartsWith('<p>Довгий опис товару.', $saved, 'обрізаний опис збережено, не порожній');
        self::assertLessThan(\strlen($description), \strlen($saved), 'збережено обрізаний опис');
        self::assertLessThanOrEqual(1_000_000 + 16, \strlen($saved), 'не довше за ліміт санітайзера (плюс закриті теги)');
    }

    /** XXE (п. 1): фід із DOCTYPE/ENTITY відхилено цілком — команда повертає помилку, у БД нічого. */
    public function testDoctypeFeedIsRefused(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('catalog:import:feed'));
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $exit = $tester->execute(['feed' => __DIR__.'/../../../../Fixtures/feeds/xxe.yml', '--source' => 'xxe', '--sync' => true]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('DOCTYPE/ENTITY', $tester->getDisplay());
        self::assertNull($em->getRepository(ProductVariant::class)->findOneBy(['sku' => 'XXE-1']));
    }

    public function testUnknownFormatFails(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('catalog:import:feed'));
        $this->expectException(InvalidArgumentException::class);
        $tester->execute(['feed' => __FILE__, '--source' => 'x', '--sync' => true]);
    }
}
