<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site\Account;

use App\Core\Cart\Entity\Cart;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Shared\Money\Money;
use App\Tests\Functional\Site\CatalogPageTestCase;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * E13 PR-7, замовлення списком у кабінеті: лише покупець з групою (гість — на вхід, без групи — підказка подати заявку),
 * розбір рядків з помилками (не знайдено, недоступно, мінімальна партія, кількість не число, дублі складаються,
 * регістр SKU), CSV у Windows-1251 із заголовком, ліміт рядків, «додати в кошик» кладе лише розпізнані рядки з ціною
 * групи, кошик іншого покупця не зачеплено (IDOR).
 */
final class BulkOrderTest extends CatalogPageTestCase
{
    private CustomerGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->group = new CustomerGroup('bulk_'.$this->suffix, 'Опт');
        $this->fx->v['V1']->addPriceTier('1', Money::of(800, 'UAH'), $this->group);
        $this->fx->v['V3']->setQuantityRules('3', '1');
        $this->em()->persist($this->group);
        $this->em()->flush();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->client->setServerParameter('REMOTE_ADDR', '203.0.113.'.random_int(1, 254));
    }

    public function testGuestAndCustomerWithoutGroup(): void
    {
        $this->client->request('GET', 'http://localhost/account/bulk-order');
        self::assertResponseRedirects('http://localhost/account/login');
        $this->client->request('POST', 'http://localhost/account/bulk-order/add', ['_token' => 'csrf-token', 'rows' => [['sku' => $this->fx->v['V1']->getSku(), 'quantity' => '1']]]);
        self::assertResponseRedirects('http://localhost/account/login');
        self::assertSame(0, $this->em()->getRepository(Cart::class)->count([]), 'гість нічого не додав');

        $this->client->loginUser($this->customer('nogroup', null), 'site');
        $crawler = $this->client->request('GET', 'http://localhost/account/bulk-order');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.bulk-no-group'));
        self::assertCount(0, $crawler->filter('form.bulk-form'));
        $this->client->request('POST', 'http://localhost/account/bulk-order/add', ['_token' => 'csrf-token', 'rows' => [['sku' => $this->fx->v['V1']->getSku(), 'quantity' => '1']]]);
        self::assertResponseRedirects('/account/bulk-order');
        self::assertSame(0, $this->em()->getRepository(Cart::class)->count([]), 'без групи нічого не додано');
    }

    public function testParseAddAndIsolation(): void
    {
        $wholesaler = $this->customer('bulk', $this->group);
        $this->client->loginUser($wholesaler, 'site');
        $v1 = $this->fx->v['V1']->getSku();
        $v2 = $this->fx->v['V2']->getSku();
        $v3 = $this->fx->v['V3']->getSku();
        $v6 = $this->fx->v['V6']->getSku();
        $lines = implode("\n", [$v1.' 2', 'NOPE-'.$this->suffix.' 3', $v6.';1', $v3."\t2", mb_strtolower($v2).';4', $v2.' 1', $v1.' abc']);
        $crawler = $this->client->request('POST', 'http://localhost/account/bulk-order', ['_token' => 'csrf-token', 'lines' => $lines]);
        self::assertResponseIsSuccessful();
        $rows = [];
        $crawler->filter('tr.bulk-row')->each(static function (Crawler $row) use (&$rows): void {
            $rows[(string) $row->attr('data-sku')] = (string) $row->attr('data-status');
        });
        self::assertSame([$v1 => 'invalid_quantity', 'NOPE-'.$this->suffix => 'not_found', $v6 => 'unavailable', $v3 => 'below_min', $v2 => 'ok'], $rows, 'дубль з помилкою псує рядок, регістр SKU не важливий');
        self::assertStringContainsString('Мінімальна партія 3', $crawler->filter('tr[data-sku="'.$v3.'"]')->text());
        self::assertSame("5\u{a0}шт", trim($crawler->filter('tr[data-sku="'.$v2.'"] td:nth-child(4)')->text()), '4 + 1 складено');
        self::assertSame("60,00\u{a0}₴", trim($crawler->filter('.bulk-total')->text()));
        self::assertCount(2, $crawler->filter('form.bulk-add input[name^="rows"]'), 'у кошик ідуть лише розпізнані');

        // без помилкового дубля V1 — ціна групи 8.00
        $crawler = $this->client->request('POST', 'http://localhost/account/bulk-order', ['_token' => 'csrf-token', 'lines' => $v1.' 2']);
        self::assertSame("8,00\u{a0}₴", trim($crawler->filter('tr[data-sku="'.$v1.'"] td:nth-child(5)')->text()), 'ціна порога групи');
        $this->client->submit($crawler->filter('form.bulk-add')->form());
        self::assertResponseRedirects('/cart/');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Додано в кошик позицій: 1', $crawler->filter('.flash-success')->text());
        self::assertSame(['2'], $crawler->filter('tr.cart-line input[name="quantity"]')->extract(['value']));
        self::assertStringContainsString("16,00\u{a0}₴", $crawler->filter('.cart-total')->text());

        // CSV з Excel: Windows-1251, заголовок, крапка з комою
        $csv = mb_convert_encoding("Артикул;Кількість\r\n".$v2.";3\r\n", 'Windows-1251', 'UTF-8');
        $path = tempnam(sys_get_temp_dir(), 'bulk');
        self::assertNotFalse($path);
        file_put_contents($path, $csv);
        $crawler = $this->client->request('POST', 'http://localhost/account/bulk-order', ['_token' => 'csrf-token', 'lines' => ''], ['csv' => new UploadedFile($path, 'order.csv', 'text/csv', null, true)]);
        self::assertResponseIsSuccessful();
        self::assertSame(['header', 'ok'], $crawler->filter('tr.bulk-row')->extract(['data-status']), 'заголовок видно в таблиці, не зникає мовчки');
        self::assertSame($v2, $crawler->filter('tr.bulk-row')->eq(1)->attr('data-sku'));
        self::assertCount(1, $crawler->filter('form.bulk-add input[name$="[sku]"]'), 'заголовок у кошик не йде');

        // десяткова кома (Excel в українській локалі): «SKU;2,5» і «SKU 2,5» — 2.5, не 2 і не артикул «SKU 2»
        $this->client->enableProfiler();
        $crawler = $this->client->request('POST', 'http://localhost/account/bulk-order', ['_token' => 'csrf-token', 'lines' => $v1.";2,5\n".$v2." 2,5\nABC,3"]);
        self::assertResponseIsSuccessful();
        self::assertSame("2,5\u{a0}шт", trim($crawler->filter('tr[data-sku="'.$v1.'"] td:nth-child(4)')->text()));
        self::assertSame("2,5\u{a0}шт", trim($crawler->filter('tr[data-sku="'.$v2.'"] td:nth-child(4)')->text()));
        self::assertSame('not_found', $crawler->filter('tr[data-sku="ABC"]')->attr('data-status'), 'кома поза цифрами — роздільник колонок');
        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        self::assertLessThanOrEqual(12, $collector->getQueryCount(), 'звірка списку без N+1: варіанти з товарами й порогами одним запитом, опції другим');

        // збій завантаження файлу не підміняється текстом з поля
        $this->client->request('POST', 'http://localhost/account/bulk-order', ['_token' => 'csrf-token', 'lines' => $v1.' 1'], ['csv' => new UploadedFile($path, 'order.csv', 'text/csv', \UPLOAD_ERR_INI_SIZE, true)]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('завеликий', $this->client->getCrawler()->filter('.bulk-error')->text());

        // забагато рядків — список не приймається
        $this->client->request('POST', 'http://localhost/account/bulk-order', ['_token' => 'csrf-token', 'lines' => str_repeat($v1." 1\n", 501)]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Забагато рядків', $this->client->getCrawler()->filter('.bulk-error')->text());

        // інший оптовик: свій порожній кошик, чужого не бачить
        $this->client->loginUser($this->customer('bulk2', $this->group), 'site');
        $this->client->request('GET', 'http://localhost/cart/');
        self::assertSelectorExists('.empty');
        $this->client->request('POST', 'http://localhost/account/bulk-order/add', ['_token' => 'csrf-token', 'rows' => [['sku' => $v2, 'quantity' => '1']]]);
        self::assertResponseRedirects('/cart/');
        $this->em()->clear();
        self::assertCount(1, $this->em()->getRepository(Cart::class)->findOneBy(['customer' => $wholesaler->getId()])?->getItems() ?? [], 'кошик першого оптовика без змін');
    }

    private function customer(string $name, ?CustomerGroup $group): Customer
    {
        $customer = new Customer($name.'-'.$this->suffix.'@example.test', 'Покупець', self::defaultLocale());
        $customer->verifyEmail('$2y$04$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012345');
        // після HTTP-запитів ядро перезавантажене — група фікстури відчеплена, беремо її зі свіжого EM
        $customer->setCustomerGroup(null === $group ? null : $this->em()->find(CustomerGroup::class, $group->getId()));
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }
}
