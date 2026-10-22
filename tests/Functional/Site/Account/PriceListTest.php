<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site\Account;

use App\Core\B2b\Entity\Company;
use App\Core\B2b\Entity\CompanyMember;
use App\Core\B2b\PriceList\PriceListCsv;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\OptionSelection;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\Pricing;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use App\Core\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Прайс-лист групи (E13 PR-8): лише схвалений оптовик з групою, лише власна група; CSV для Excel (BOM, «;», кома в цінах
 * для uk); назва-формула екранована; потік без ПД і контактів; ліміт на покупця; прайс будь-якої групи для менеджера в адмінці.
 */
final class PriceListTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $site;
    private string $admin;
    private string $suffix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects(false);
        /** @var string $siteHost */
        $siteHost = static::getContainer()->getParameter('site_host');
        /** @var string $adminHost */
        $adminHost = static::getContainer()->getParameter('admin_host');
        $this->site = 'https://'.$siteHost;
        $this->admin = 'https://'.$adminHost;
        $this->client->setServerParameter('REMOTE_ADDR', '203.0.113.'.random_int(1, 254));
        $this->suffix = bin2hex(random_bytes(3));
    }

    public function testGuestRetailInactiveAndMemberWithoutGroupGetNoFile(): void
    {
        $this->client->request('GET', $this->site.'/account/wholesale/price-list');
        self::assertResponseRedirects($this->site.'/account/login');

        $retail = $this->createCustomer();
        $this->client->loginUser($retail, 'site');
        $this->client->request('GET', $this->site.'/account/wholesale/price-list');
        self::assertResponseStatusCodeSame(403);

        // група без компанії (призначена вручну, без схвалення заявки) — теж ні
        $withGroup = $this->createCustomer();
        $withGroup->setCustomerGroup($this->group());
        $this->em()->flush();
        $this->client->loginUser($withGroup, 'site');
        $this->client->request('GET', $this->site.'/account/wholesale/price-list');
        self::assertResponseStatusCodeSame(403);

        // вимкнений акаунт: сесію гасить security (authVersion/checker) — на вхід
        $inactive = $this->wholesaler($this->group());
        $inactive->setActive(false);
        $this->em()->flush();
        $this->client->loginUser($inactive, 'site');
        $this->client->request('GET', $this->site.'/account/wholesale/price-list');
        self::assertResponseRedirects($this->site.'/account/login');
    }

    public function testWholesalerDownloadsOwnGroupPricesEscapedForExcel(): void
    {
        [$own, $other] = [$this->group(), $this->group()];
        $this->catalog($own, $other);
        $customer = $this->wholesaler($own);
        $this->client->loginUser($customer, 'site');

        $crawler = $this->client->request('GET', $this->site.'/account/wholesale');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href$="/account/wholesale/price-list"]'));

        $body = $this->download($this->site.'/account/wholesale/price-list');
        $response = $this->client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename=price-list-'.$own->getCode().'-', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));

        self::assertSame('0', \ini_get('max_execution_time'), 'потік знімає ліміт часу виконання (рев\'ю #63 M2\')');
        self::assertStringStartsWith(PriceListCsv::BOM, $body, 'BOM для Excel');
        self::assertTrue(mb_check_encoding($body, 'UTF-8'));
        $lines = explode("\r\n", rtrim(substr($body, 3)));
        self::assertSame(PriceListCsv::TRAILER.' '.(\count($lines) - 2), array_pop($lines), 'службовий трейлер з кількістю рядків даних (рев\'ю #63 M2)');
        self::assertSame(['Артикул', 'Назва', 'Категорія', 'Одиниця', 'Від кількості', 'Ціна для вас', 'Роздрібна ціна', 'Валюта', 'Наявність', 'Залишок', 'Мінімальна партія', 'Кратність', 'В упаковці'], str_getcsv($lines[0], ';', '"', ''));
        $rows = [];
        foreach (\array_slice($lines, 1) as $line) {
            $cells = str_getcsv($line, ';', '"', '');
            $rows[$cells[0].'@'.$cells[4]] = $cells;
        }
        $sku = 'PL-'.$this->suffix;
        // мінімальна партія 2: поріг групи «від 1» діє, роздріб — базова ціна; поріг групи «від 10» — окремий рядок
        self::assertSame(['Двері прайс '.$this->suffix, 'Двері '.$this->suffix.' / Міжкімнатні '.$this->suffix, 'шт', '2', '80,00', '100,00', 'UAH', 'є', '', '2', '1', ''], \array_slice($rows[$sku.'@2'], 1));
        // роздріб від 10 шт. — загальний поріг «від 3» (95), оптовик його не бачить: у нього свої пороги
        self::assertSame(['70,00', '95,00'], \array_slice($rows[$sku.'@10'], 5, 2));
        // чужа група: її поріг 50 не видно ніде
        foreach ($rows as $cells) {
            self::assertNotContains('50,00', $cells);
        }
        // назва-формула: апостроф спереду, Excel покаже текст; рядок за загальним порогом «від 5» (у групи своїх нема)
        $formula = $rows['PLF-'.$this->suffix.'@1'];
        self::assertSame("'=HYPERLINK(\"https://evil.test\")", $formula[1]);
        self::assertSame(['90,00', '90,00', 'UAH', 'під замовлення'], \array_slice($formula, 5, 4));
        self::assertSame(['85,00', '85,00'], \array_slice($rows['PLF-'.$this->suffix.'@5'], 5, 2));
        self::assertArrayNotHasKey('PLX-'.$this->suffix.'@1', $rows, 'вимкнений варіант не потрапляє');
        // товар з платною опцією за замовчуванням: ціна як на картці й у кошику (рев'ю #63 M1), не база
        $optionedVariant = $this->em()->getRepository(ProductVariant::class)->findOneBy(['sku' => 'PLO-'.$this->suffix]) ?? self::fail();
        $card = static::getContainer()->get(Pricing::class)->cardQuote($optionedVariant, 'uk', $this->em()->find(CustomerGroup::class, $own->getId()));
        self::assertSame('120,00', str_replace('.', ',', $card->price->toDecimalString()), 'картка: база 100 + опція 20');
        self::assertSame(['120,00', '120,00'], \array_slice($rows['PLO-'.$this->suffix.'@1'], 5, 2));
        self::assertStringNotContainsString($customer->getEmail(), $body);
        self::assertStringNotContainsString('@', str_replace("'=HYPERLINK", '', $body), 'ні контактів, ні email у файлі');

        // інша група бачить свої ціни, а не чужі
        $this->client->loginUser($this->wholesaler($other), 'site');
        $body = $this->download($this->site.'/account/wholesale/price-list');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($sku.';"Двері прайс '.$this->suffix.'";', $body);
        self::assertStringContainsString(';2;50,00;100,00;', $body);
        self::assertStringNotContainsString('80,00', $body);
    }

    public function testRussianLocaleHeadersAndRateLimit(): void
    {
        $own = $this->group();
        $this->catalog($own, $this->group());
        // акція з ціллю-категорією: Promotion::appliesTo читає категорії й бренд товару — пакет має їх підвантажити (рев'ю #63 M3)
        $promotion = new Promotion('Прайс тест '.$this->suffix, 'UAH');
        $promotion->setPercentDiscount('5');
        $promotion->addCategory($this->em()->getRepository(Category::class)->findAll()[0] ?? self::fail());
        $this->em()->persist($promotion);
        $this->em()->flush();
        $this->client->loginUser($this->wholesaler($own), 'site');
        $this->client->request('GET', $this->site.'/account/wholesale'); // журнал запитів фікстури скидається першим запитом
        $this->client->enableProfiler();
        $body = $this->download($this->site.'/ru/account/wholesale/price-list');
        self::assertResponseIsSuccessful();
        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        self::assertLessThanOrEqual(25, $collector->getQueryCount(), 'прайс на пакет: без запиту на кожен товар');
        self::assertStringContainsString("\xEF\xBB\xBFАртикул;Название;Категория;", $body);
        self::assertStringContainsString(';"Двери прайс '.$this->suffix.'";"Двери '.$this->suffix.' / Міжкімнатні '.$this->suffix.'";шт;', $body, 'назва мовою запиту, категорія без перекладу — мовою за замовчуванням');

        for ($i = 0; $i < 9; ++$i) {
            $this->download($this->site.'/account/wholesale/price-list');
            self::assertResponseIsSuccessful();
        }
        $crawler = $this->client->request('GET', $this->site.'/account/wholesale/price-list');
        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('Забагато завантажень', $crawler->filter('h1')->text());
    }

    public function testManagerDownloadsAnyGroupFromAdmin(): void
    {
        [$own, $other] = [$this->group(), $this->group()];
        $this->catalog($own, $other);

        $this->client->request('GET', $this->admin.'/price-list/'.$other->getId());
        self::assertResponseRedirects($this->admin.'/login');

        $user = new User('manager-'.$this->suffix.'@example.test', 'Менеджер', UserRole::Manager);
        $user->setPasswordHash(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $this->em()->persist($user);
        $this->em()->flush();
        $this->client->loginUser($user, 'admin');
        $body = $this->download($this->admin.'/price-list/'.$other->getId().'?locale=ru');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString("\xEF\xBB\xBFАртикул;Название;", $body);
        self::assertStringContainsString(';2;50,00;100,00;', $body);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        // маршрут адмінки на хості сайту — 404 (AdminHostGuard)
        $this->client->request('GET', $this->site.'/price-list/'.$other->getId());
        self::assertResponseStatusCodeSame(404);
    }

    /** Вміст StreamedResponse KernelBrowser збирає у внутрішню відповідь (HttpKernelBrowser::filterResponse). */
    private function download(string $url): string
    {
        set_time_limit(60);
        $this->client->request('GET', $url);

        return $this->client->getInternalResponse()->getContent();
    }

    private function catalog(CustomerGroup $own, CustomerGroup $other): void
    {
        $parent = new Category();
        $parent->translate('uk')->setName('Двері '.$this->suffix);
        $parent->translate('ru')->setName('Двери '.$this->suffix);
        $child = new Category($parent);
        $child->translate('uk')->setName('Міжкімнатні '.$this->suffix);
        $this->em()->persist($parent);
        $this->em()->persist($child);

        $product = new Product('PL-'.$this->suffix, 'UAH');
        $product->translate('uk')->setName('Двері прайс '.$this->suffix)->setSlug('dveri-prais-'.$this->suffix);
        $product->translate('ru')->setName('Двери прайс '.$this->suffix)->setSlug('dveri-prais-ru-'.$this->suffix);
        $product->addCategory($child);
        $variant = $product->getDefaultVariant();
        $variant->setPrice(Money::of(10_000, 'UAH'));
        $variant->setStock('20');
        $variant->setQuantityRules('2', '1');
        $variant->addPriceTier('1', Money::of(8_000, 'UAH'), $own);
        $variant->addPriceTier('10', Money::of(7_000, 'UAH'), $own);
        $variant->addPriceTier('1', Money::of(5_000, 'UAH'), $other);
        $variant->addPriceTier('3', Money::of(9_500, 'UAH'));
        $disabled = $product->addVariant('PLX-'.$this->suffix);
        $disabled->setPrice(Money::of(10_000, 'UAH'));
        $disabled->setActive(false);
        $this->em()->persist($product);

        $optioned = new Product('PLO-'.$this->suffix, 'UAH');
        $optioned->translate('uk')->setName('Двері з опцією '.$this->suffix)->setSlug('opt-'.$this->suffix);
        $optioned->getDefaultVariant()->setPrice(Money::of(10_000, 'UAH'));
        $optioned->getDefaultVariant()->setStock('5');
        $extras = $optioned->addOptionGroup('extras', OptionSelection::Single, true);
        $extras->translate('uk')->setName('Петлі');
        $hidden = $extras->addAbsoluteModifier('hidden', Money::of(2_000, 'UAH'));
        $hidden->translate('uk')->setLabel('Приховані');
        $extras->markDefault($hidden);
        $this->em()->persist($optioned);

        $formula = new Product('PLF-'.$this->suffix, 'UAH');
        $formula->translate('uk')->setName('=HYPERLINK("https://evil.test")')->setSlug('formula-'.$this->suffix);
        $formula->getDefaultVariant()->setPrice(Money::of(9_000, 'UAH'));
        $formula->getDefaultVariant()->setStockPolicy(StockPolicy::MadeToOrder);
        $formula->getDefaultVariant()->addPriceTier('5', Money::of(8_500, 'UAH'));
        $this->em()->persist($formula);
        $this->em()->flush();
    }

    private function wholesaler(CustomerGroup $group): Customer
    {
        $customer = $this->createCustomer();
        $company = new Company('ТОВ Прайс '.$this->suffix.random_int(10, 99), (string) random_int(10_000_000, 99_999_999), false, 'Контакт', null);
        $this->em()->persist($company);
        $this->em()->persist(new CompanyMember($company, $customer));
        // після потоку прайса EntityManager очищений, група відокремлена: беремо керований екземпляр
        $customer->setCustomerGroup($this->em()->find(CustomerGroup::class, $group->getId()) ?? self::fail('групи нема'));
        $this->em()->flush();

        return $customer;
    }

    private function createCustomer(): Customer
    {
        $customer = new Customer('pl-'.bin2hex(random_bytes(4)).'@example.test', 'Покупець', 'uk');
        $customer->verifyEmail(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, 'correct horse battery'));
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    private function group(): CustomerGroup
    {
        $group = new CustomerGroup('pl_'.bin2hex(random_bytes(3)), 'Опт '.bin2hex(random_bytes(2)));
        $this->em()->persist($group);
        $this->em()->flush();

        return $group;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
