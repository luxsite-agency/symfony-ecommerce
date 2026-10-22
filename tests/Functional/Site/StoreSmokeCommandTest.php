<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Core\Cart\Entity\Cart;
use App\Core\Content\Entity\Page;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * store:smoke (кандидат релізу в bin/store.d/_remote.sh): ключові сторінки сайту через ядро кожною мовою — головна, блог,
 * кошик, кабінет, перша категорія/товар/сторінка з БД; усе 2xx/3xx і з каркасом base → код 0.
 */
final class StoreSmokeCommandTest extends CatalogPageTestCase
{
    public function testSmokeRendersKeyPagesForEveryLocale(): void
    {
        $page = new Page();
        $page->setPublished(true);
        foreach (self::locales() as $locale) {
            $page->translate($locale)->setTitle($locale.' smoke '.$this->suffix)->setBody('<p>x</p>');
        }
        $this->em()->persist($page);
        $this->em()->flush();

        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('store:smoke'));
        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode(), $display);
        foreach (self::locales() as $locale) {
            foreach (['site_home', 'site_blog', 'site_cart', 'site_checkout', 'site_account_login', 'site_category', 'site_product', 'site_page'] as $route) {
                self::assertStringContainsString('OK   '.$route.' '.$locale, $display, $route.' '.$locale);
            }
        }
        self::assertStringNotContainsString('FAIL', $display);
        self::assertStringNotContainsString('site_post', $display, 'без опублікованих записів блогу — запис не перевіряється');
        self::assertStringNotContainsString('site_order_accepted', $display, 'без прострочених замовлень — «прийнято» не перевіряється');
        self::assertSame(0, $this->em()->getRepository(Cart::class)->count([]), 'тимчасовий кошик smoke видалено');

        $tester->execute(['--default-only' => true]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringNotContainsString(' en ', $tester->getDisplay(), '--default-only: лише мова за замовчуванням');
    }
}
