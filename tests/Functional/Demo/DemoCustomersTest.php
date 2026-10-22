<?php

declare(strict_types=1);

namespace App\Tests\Functional\Demo;

use App\Core\Customer\Auth\AuthTokens;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerAddress;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Customer\Enum\AuthTokenPurpose;
use App\Core\Demo\DemoAccounts;
use App\Core\Demo\DemoCatalog;
use App\Core\Demo\DemoCustomers;
use App\Core\Demo\Message\ResetDemoCustomers;
use App\Core\Demo\MessageHandler\ResetDemoCustomersHandler;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Демо-покупці (DemoCustomers, DemoAccounts): seed у демо-режимі (DEMO_CUSTOMER_PASSWORD) створює роздрібного й оптового
 * з підтвердженим email, адресами, кошиком, обраним і замовленнями по ~180 днях, заявника на опт; повторний seed нічого не
 * дублює; блок «Демо-доступ» на вході лише в демо-режимі, вхід кнопкою обома акаунтами; оптові ціни в замовленнях і
 * кошику; захист демо-акаунтів від зміни пароля/email, видалення, експорту й скидання пароля; purge прибирає все.
 * Без DAMA-відкату (перебудова індексу фільтрів — DDL): усе прибирається в tearDown.
 */
#[SkipDatabaseRollback]
final class DemoCustomersTest extends WebTestCase
{
    private const string PASSWORD = 'Demo-test-2026';
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;
    private string $site;

    protected function setUp(): void
    {
        $_SERVER['DEMO_CUSTOMER_PASSWORD'] = $_ENV['DEMO_CUSTOMER_PASSWORD'] = self::PASSWORD;
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = static::getContainer()->get(Connection::class);
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->site = 'https://'.$host;
        $this->client->setServerParameter('HTTP_ORIGIN', $this->site);
        self::assertSame(0, $this->demoCustomers(), 'БД тестів без демо-покупців з попередніх прогонів');
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->command()->execute(['--purge' => true]);
        unset($_SERVER['DEMO_CUSTOMER_PASSWORD'], $_ENV['DEMO_CUSTOMER_PASSWORD']);
        parent::tearDown();
    }

    public function testSeedLoginsPricesGuardAndPurge(): void
    {
        // група, засіяна до PR-7 (без мінімуму і точних залишків): seed має дописати поля (рев'ю #66 v2 L1)
        $stale = new CustomerGroup(DemoCatalog::CUSTOMER_GROUP, 'Оптовий');
        $this->em->persist($stale);
        $this->em->flush();
        $this->em->clear();

        $tester = $this->command();
        self::assertSame(0, $tester->execute(['--no-images' => true]), $tester->getDisplay());
        self::assertSame(3, $this->demoCustomers());
        $group = $this->em->getRepository(CustomerGroup::class)->findOneBy(['code' => DemoCatalog::CUSTOMER_GROUP]);
        self::assertNotNull($group);
        self::assertTrue($group->showsExactStock(), 'стара група отримала точні залишки');
        self::assertSame(DemoCatalog::WHOLESALE_MIN_ORDER * 100, $group->getMinOrderAmountMinor(), 'стара група отримала мінімум замовлення');
        $retail = $this->customer(DemoAccounts::RETAIL);
        $wholesale = $this->customer(DemoAccounts::WHOLESALE);
        self::assertTrue($retail->isEmailVerified());
        self::assertSame('+380000000101', $retail->getPhone());
        self::assertSame(DemoCatalog::CUSTOMER_GROUP, $wholesale->getCustomerGroup()?->getCode());
        self::assertSame(2, $this->em->getRepository(CustomerAddress::class)->count(['customer' => $retail]));
        self::assertSame(1, $this->em->getRepository(CustomerAddress::class)->count(['customer' => $wholesale]));
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM customer_wishlist WHERE customer_id = ?', [$retail->getId()]));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM cart_item i JOIN cart c ON c.id = i.cart_id WHERE c.customer_id = ?', [$retail->getId()]));
        self::assertSame(8, $this->orders($retail));
        self::assertSame(5, $this->orders($wholesale));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM wholesale_application WHERE status = 'new' AND customer_id = (SELECT id FROM customer WHERE email = ?)", [DemoAccounts::APPLICANT]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM company WHERE tax_id = ?', [DemoCustomers::COMPANY_TAX_ID]));
        // замовлення по ~180 днях, у різних станах; оптові — за цінами групи (поріг групи MT-001: 85 грн/м)
        self::assertGreaterThanOrEqual(150, (int) $this->db->fetchOne("SELECT DATEDIFF(NOW(), MIN(placed_at)) FROM sales_order WHERE comment LIKE 'DEMO #%'"));
        self::assertGreaterThanOrEqual(5, (int) $this->db->fetchOne("SELECT COUNT(DISTINCT status) FROM sales_order WHERE comment LIKE 'DEMO #%'"));
        self::assertSame(['demo_wholesale', '8500'], array_map('strval', $this->db->fetchNumeric(
            'SELECT o.customer_group_code, i.unit_price_minor FROM sales_order o JOIN sales_order_item i ON i.order_id = o.id WHERE o.customer_id = ? AND i.sku = ? ORDER BY o.id LIMIT 1',
            [$wholesale->getId(), 'DEMO-MT-001'],
        ) ?: []));

        // повторний seed нічого не дублює
        $again = $this->command();
        self::assertSame(0, $again->execute(['--no-images' => true]));
        self::assertStringContainsString('нічого не додано', $again->getDisplay());
        self::assertSame(3, $this->demoCustomers());
        self::assertSame(8, $this->orders($retail));

        // блок «Демо-доступ» і вхід кнопкою роздрібним
        $crawler = $this->client->request('GET', $this->site.'/account/login');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.demo-access'));
        self::assertStringContainsString(self::PASSWORD, $crawler->filter('.demo-credentials')->text());
        $this->client->submit($crawler->filter('form.demo-login-retail')->form());
        self::assertResponseRedirects($this->site.'/account/');
        $orders = $this->client->request('GET', $this->site.'/account/orders');
        self::assertResponseIsSuccessful();
        self::assertCount(8, $orders->filter('table.orders tbody tr'));
        $addresses = $this->client->request('GET', $this->site.'/account/addresses');
        self::assertCount(2, $addresses->filter('li.address'));
        $cart = $this->client->request('GET', $this->site.'/cart/');
        self::assertCount(2, $cart->filter('tr.cart-line'), 'кошик демо-покупця з двома позиціями');

        // захист демо-акаунта: пароль, email, видалення, експорт — «у демо недоступно»
        foreach (['/account/profile/password', '/account/profile/email', '/account/privacy/delete', '/account/privacy/export'] as $path) {
            $blocked = $this->client->request('GET', $this->site.$path);
            self::assertResponseStatusCodeSame(403, $path);
            self::assertCount(1, $blocked->filter('.demo-blocked'), $path);
        }
        self::assertTrue($this->customer(DemoAccounts::RETAIL)->isActive());
        $this->client->request('POST', $this->site.'/account/logout', ['_csrf_token' => 'csrf-token']);
        $crawler = $this->client->request('GET', $this->site.'/account/password');
        $this->client->submit($crawler->filter('form.account-form')->form(['email_request[email]' => DemoAccounts::RETAIL]));
        self::assertResponseStatusCodeSame(403, 'скидання пароля на демо-адресу закрите');
        // реєстрація з демо-адресою (видала б reset-токен) — 403; з боку Core AuthTokens для демо нічого не зберігає (рев'ю #66 M3)
        $crawler = $this->client->request('GET', $this->site.'/account/register');
        $form = $crawler->filter('form.account-form')->form(['registration[email]' => DemoAccounts::RETAIL]);
        $consent = $form['registration[consent]'];
        self::assertInstanceOf(ChoiceFormField::class, $consent);
        $consent->tick();
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(403);
        static::getContainer()->get(AuthTokens::class)->issue($this->customer(DemoAccounts::RETAIL), AuthTokenPurpose::ResetPassword);
        $this->em->flush();
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM customer_auth_token t JOIN customer c ON c.id = t.customer_id WHERE c.email IN (?)', [DemoAccounts::EMAILS], [ArrayParameterType::STRING]), 'для демо-акаунтів токенів нема');

        // оптовий: вхід кнопкою, статус оптовика, ціна групи в кошику (MT-001: 85 замість 98)
        $crawler = $this->client->request('GET', $this->site.'/account/login');
        $this->client->submit($crawler->filter('form.demo-login-wholesale')->form());
        self::assertResponseRedirects($this->site.'/account/');
        $status = $this->client->request('GET', $this->site.'/account/wholesale');
        self::assertStringContainsString('ТОВ «Демо Опт»', $status->filter('.wholesale-status')->text());
        $this->addToCart('DEMO-MT-001', '2');
        $cart = $this->client->request('GET', $this->site.'/cart/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('170,00', $cart->filter('tr.cart-line[data-sku="DEMO-MT-001"] .price')->last()->text(), '2 м × 85 (поріг групи), не 98');
        $this->client->request('POST', $this->site.'/account/logout', ['_csrf_token' => 'csrf-token']);

        // замовлення відвідувача з демо-акаунта (без позначки DEMO) теж зникає при скиданні (рев'ю #66 L1), резерв повертається (M2)
        $stockBefore = $this->stock('DEMO-MT-001');
        $this->db->executeStatement('UPDATE sales_order SET comment = NULL WHERE comment LIKE ?', ['DEMO #21:%']);

        // нічне скидання: кошики зникають, акаунти знову як після seed, залишки демо-товарів ті самі після двох скидань
        static::getContainer()->get(ResetDemoCustomersHandler::class)(new ResetDemoCustomers());
        $this->em->clear();
        self::assertSame(3, $this->demoCustomers());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM sales_order WHERE email = ? AND customer_id IS NULL', [DemoAccounts::RETAIL]), 'без «гостьових» хвостів');
        static::getContainer()->get(ResetDemoCustomersHandler::class)(new ResetDemoCustomers());
        $this->em->clear();
        self::assertSame($stockBefore, $this->stock('DEMO-MT-001'), 'скидання повертає резерв: склад не тане щоночі');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM cart WHERE customer_id = (SELECT id FROM customer WHERE email = ?)', [DemoAccounts::WHOLESALE]));
        self::assertSame(8, $this->orders($this->customer(DemoAccounts::RETAIL)));
        self::assertTrue($this->customer(DemoAccounts::WHOLESALE)->getCustomerGroup()?->showsExactStock() ?? false, 'оптова демо-група з точними залишками (PR-7)');

        // purge прибирає покупців, компанію, кошики, замовлення
        $purge = $this->command();
        self::assertSame(0, $purge->execute(['--purge' => true]), $purge->getDisplay());
        self::assertSame(0, $this->demoCustomers());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM company WHERE tax_id = ?', [DemoCustomers::COMPANY_TAX_ID]));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM sales_order WHERE comment LIKE 'DEMO #%'"));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM customer_address'));
    }

    public function testWithoutDemoPasswordNoCustomersAndNoLoginBlock(): void
    {
        unset($_SERVER['DEMO_CUSTOMER_PASSWORD'], $_ENV['DEMO_CUSTOMER_PASSWORD']);
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = static::getContainer()->get(Connection::class);
        $tester = $this->command();
        self::assertSame(0, $tester->execute(['--no-images' => true, '--no-orders' => false]), $tester->getDisplay());
        self::assertSame(0, $this->demoCustomers(), 'без пароля демо-покупців нема');
        $crawler = $this->client->request('GET', $this->site.'/account/login');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.demo-access'), 'на звичайному магазині блоку нема');
        static::getContainer()->get(ResetDemoCustomersHandler::class)(new ResetDemoCustomers());
        self::assertSame(0, $this->demoCustomers());
    }

    private function addToCart(string $sku, string $quantity): void
    {
        $variantId = (int) $this->db->fetchOne('SELECT id FROM catalog_product_variant WHERE sku = ?', [$sku]);
        $slug = (string) $this->db->fetchOne('SELECT t.slug FROM catalog_product_translation t JOIN catalog_product_variant v ON v.product_id = t.product_id WHERE v.id = ? AND t.locale = ?', [$variantId, 'uk']);
        $crawler = $this->client->request('GET', $this->site.'/'.$slug);
        self::assertResponseIsSuccessful($slug);
        $token = (string) $crawler->filter('form.add-to-cart input[name="_token"]')->attr('value');
        $this->client->request('POST', $this->site.'/cart/add', ['variant' => $variantId, 'quantity' => $quantity, '_token' => $token]);
        self::assertResponseRedirects();
    }

    private function stock(string $sku): string
    {
        return (string) $this->db->fetchOne('SELECT stock FROM catalog_product_variant WHERE sku = ?', [$sku]);
    }

    private function orders(Customer $customer): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM sales_order WHERE customer_id = ?', [$customer->getId()]);
    }

    private function customer(string $email): Customer
    {
        $this->em->clear();

        return $this->em->getRepository(Customer::class)->findOneBy(['email' => $email]) ?? self::fail($email);
    }

    private function demoCustomers(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM customer WHERE email IN (?)', [DemoAccounts::EMAILS], [ArrayParameterType::STRING]);
    }

    private function command(): CommandTester
    {
        return new CommandTester((new Application(static::$kernel ?? self::bootKernel()))->find('app:demo:seed'));
    }
}
