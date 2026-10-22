<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site\Account;

use App\Core\B2b\Entity\Company;
use App\Core\B2b\Entity\CompanyMember;
use App\Core\B2b\Entity\WholesaleApplication;
use App\Core\B2b\Enum\ApplicationStatus;
use App\Core\B2b\Message\SendWholesaleDecisionMail;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * E13 PR-5: заявка «Стати оптовим клієнтом» з кабінету (лише залогінений з підтвердженим email, одна активна
 * заявка, CSRF, ліміт), схвалення й відхилення в адмінці (група призначена, компанія створена, лист покупцю),
 * повторна заявка після відхилення. Сайт на site_host, адмінка на admin_host, один клієнт на обидва.
 */
final class WholesaleApplicationTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $site;
    private string $admin;

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
        $this->client->setServerParameter('HTTP_ORIGIN', $this->site);
    }

    public function testGuestAndUnverifiedCannotApply(): void
    {
        $this->client->request('GET', $this->site.'/account/wholesale');
        self::assertResponseRedirects($this->site.'/account/login', null, 'гість іде на вхід');
        $this->client->request('POST', $this->site.'/account/wholesale', ['wholesale_application' => ['companyName' => 'X', 'taxId' => '12345678', 'contactName' => 'Y']]);
        self::assertResponseRedirects($this->site.'/account/login', null, 'POST гостя не створює заявки');
        self::assertSame(0, $this->em()->getRepository(WholesaleApplication::class)->count([]), 'заявок від гостя нема');

        // неперевірений покупець (checker його не впускає; loginUser обходить checker, як і майбутні сценарії)
        $unverified = new Customer(self::email(), 'Без підтвердження', 'uk');
        $this->em()->persist($unverified);
        $this->em()->flush();
        $this->client->loginUser($unverified, 'site');
        $this->client->request('GET', $this->site.'/account/wholesale');
        self::assertResponseStatusCodeSame(403);
    }

    public function testApplyApproveAssignsGroupAndCreatesCompany(): void
    {
        $email = self::email();
        $customer = $this->createCustomer($email);
        $this->client->loginUser($customer, 'site');

        $crawler = $this->client->request('GET', $this->site.'/account/wholesale');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $this->client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertCount(1, $crawler->filter('form.wholesale-form'));

        // невалідна форма: код не 8 і не 10 цифр
        $this->client->submit($crawler->filter('form.wholesale-form')->form([
            'wholesale_application[companyName]' => 'ТОВ «Ромашка»',
            'wholesale_application[taxId]' => '1234567',
            'wholesale_application[contactName]' => 'Олена',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('8 цифр', $this->client->getCrawler()->filter('.form-error')->text());

        // чужий Origin: stateless CSRF відкидає
        $this->client->request('POST', $this->site.'/account/wholesale', ['wholesale_application' => [
            'companyName' => 'ТОВ «Ромашка»', 'taxId' => '12345678', 'contactName' => 'Олена', '_token' => 'csrf-token',
        ]], server: ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getRepository(WholesaleApplication::class)->count(['customer' => $customer->getId()]));

        $crawler = $this->client->request('GET', $this->site.'/account/wholesale');
        $this->client->submit($crawler->filter('form.wholesale-form')->form([
            'wholesale_application[companyName]' => 'ТОВ «Ромашка» <b>XSS</b>',
            'wholesale_application[taxId]' => '1234 5678',
            'wholesale_application[vatPayer]' => '1',
            'wholesale_application[contactName]' => 'Олена',
            'wholesale_application[contactPhone]' => '+380671234567',
            'wholesale_application[note]' => 'Близько 50 дверей на місяць',
        ]));
        self::assertResponseRedirects($this->site.'/account/wholesale');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Заявку надіслано', $crawler->filter('.flash-success')->text());
        self::assertStringContainsString('чекає на розгляд', $crawler->filter('.wholesale-status')->text());
        self::assertCount(0, $crawler->filter('form.wholesale-form'), 'поки заявка чекає, форми нема');

        $application = $this->application($customer);
        self::assertSame(ApplicationStatus::New, $application->getStatus());
        self::assertSame('12345678', $application->getTaxId(), 'пробіли прибрано');
        self::assertTrue($application->isVatPayer());

        // повторна заявка поверх активної: без нової в БД
        $this->client->request('POST', $this->site.'/account/wholesale', ['wholesale_application' => [
            'companyName' => 'Інша', 'taxId' => '87654321', 'contactName' => 'Хтось', '_token' => 'csrf-token',
        ]]);
        self::assertSame(1, $this->em()->getRepository(WholesaleApplication::class)->count(['customer' => $customer->getId()]));

        // адмінка: список і перегляд
        $group = $this->group();
        $manager = $this->loginAdmin();
        $crawler = $this->client->request('GET', $this->admin.'/wholesale-application');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ромашка', $crawler->filter('table')->text());
        $crawler = $this->client->request('GET', $this->admin.'/wholesale-application/'.$application->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Близько 50 дверей', $crawler->text());

        // схвалення без групи: відмова з повідомленням, стан не змінився
        $form = $crawler->filter('form.wholesale-approve')->form();
        $this->client->request('POST', $form->getUri(), ['decision' => 'approve', 'group' => '', 'token' => $form->get('token')->getValue()]);
        self::assertResponseRedirects();
        self::assertStringContainsString('Оберіть групу', $this->client->followRedirect()->filter('.alert-danger')->text());
        self::assertSame(ApplicationStatus::New, $this->application($customer)->getStatus());

        // підроблений CSRF: 403
        $this->client->request('POST', $form->getUri(), ['decision' => 'approve', 'group' => (string) $group->getId(), 'token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $form->getUri(), ['decision' => 'approve', 'group' => (string) $group->getId(), 'token' => $form->get('token')->getValue()]);
        self::assertResponseRedirects();
        $mails = $this->sentMails();
        self::assertCount(1, $mails);
        self::assertSame(SendWholesaleDecisionMail::APPROVED, $mails[0]->kind);
        self::assertSame($email, $mails[0]->email);
        self::assertStringStartsWith($this->site.'/account/wholesale', $mails[0]->url, 'посилання на сайт, не на адмінку');
        self::assertSame('', $mails[0]->reason, 'без коментаря менеджера блок «коментар» у листі порожній');
        $flash = $this->client->followRedirect()->filter('.alert-success');
        self::assertStringContainsString('схвалено', $flash->text());
        self::assertCount(0, $flash->filter('b'), 'флеш EasyAdmin рендериться |raw: назви компанії з даних покупця в ньому нема (рев\'ю #51)');
        self::assertStringNotContainsString('Ромашка', $flash->text());

        $application = $this->application($customer);
        self::assertSame(ApplicationStatus::Approved, $application->getStatus());
        self::assertSame($manager->getEmail(), $application->getReviewedByEmail());
        self::assertNotNull($application->getReviewedAt());
        self::assertNull($application->getReviewComment());
        self::assertSame($group->getId(), $application->getAssignedGroup()?->getId());
        self::assertSame($group->getId(), $application->getCustomer()->getCustomerGroup()?->getId(), 'групу призначено покупцю');
        $company = $application->getCompany();
        self::assertInstanceOf(Company::class, $company);
        self::assertSame('ТОВ «Ромашка» <b>XSS</b>', $company->getName());
        self::assertSame('12345678', $company->getTaxId());
        self::assertTrue($company->isVatPayer());
        $member = $this->em()->getRepository(CompanyMember::class)->findOneBy(['customer' => $customer->getId()]);
        self::assertInstanceOf(CompanyMember::class, $member);
        self::assertSame($company->getId(), $member->getCompany()->getId());

        // повторне рішення по розглянутій заявці: відмова
        $this->client->request('POST', $form->getUri(), ['decision' => 'reject', 'comment' => 'ще раз', 'token' => $form->get('token')->getValue()]);
        self::assertStringContainsString('вже розглянуто', $this->client->followRedirect()->filter('.alert-danger')->text());
        self::assertSame(ApplicationStatus::Approved, $this->application($customer)->getStatus());

        // кабінет: статус «оптовий клієнт», форми нема, нова заявка неможлива
        $crawler = $this->client->request('GET', $this->site.'/account/wholesale');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ромашка', $crawler->filter('.wholesale-status')->text());
        self::assertCount(0, $crawler->filter('.wholesale-status b'), 'назва компанії екранована');
        self::assertStringContainsString($group->getName(), $crawler->filter('.wholesale-status')->text());
        self::assertCount(0, $crawler->filter('form.wholesale-form'));
        $this->client->request('POST', $this->site.'/account/wholesale', ['wholesale_application' => [
            'companyName' => 'Ще одна', 'taxId' => '1234567890', 'contactName' => 'Хтось', '_token' => 'csrf-token',
        ]]);
        self::assertSame(1, $this->em()->getRepository(WholesaleApplication::class)->count(['customer' => $customer->getId()]));

        // компанії в адмінці
        $crawler = $this->client->request('GET', $this->admin.'/company');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($email, $crawler->filter('table')->text());
    }

    /** Фільтр за станом у списку; схвалення з коментарем: він іде покупцю в листі «схвалено»; сирітська компанія оновлюється. */
    public function testStatusFilterCommentInApprovalMailAndOrphanCompanyReuse(): void
    {
        $s = bin2hex(random_bytes(3));
        $taxId = '2'.substr((string) random_int(1000000, 9999999), 0, 7);
        $orphan = new Company('Стара назва '.$s, $taxId, false, 'Старий контакт', null);
        $this->em()->persist($orphan);
        $this->em()->flush();

        $pending = $this->createCustomer(self::email());
        $this->client->loginUser($pending, 'site');
        $this->apply('Нова '.$s, $taxId);
        $application = $this->application($pending);
        $other = $this->createCustomer(self::email());
        $this->client->loginUser($other, 'site');
        $this->apply('Відхилена '.$s, '1234567890');
        $rejected = $this->application($other);

        $group = $this->group();
        $this->loginAdmin();
        $crawler = $this->client->request('GET', $this->admin.'/wholesale-application/'.$rejected->getId());
        $form = $crawler->filter('form.wholesale-reject')->form();
        $this->client->request('POST', $form->getUri(), ['decision' => 'reject', 'comment' => 'ні', 'token' => $form->get('token')->getValue()]);

        self::assertResponseRedirects();
        $rows = function (string $query): string {
            $text = $this->client->request('GET', $this->admin.'/wholesale-application?'.$query)->filter('table tbody')->text();
            self::assertResponseIsSuccessful();

            return $text;
        };
        self::assertStringContainsString('Нова '.$s, $rows('filters[status][comparison]==&filters[status][value]=new'));
        self::assertStringNotContainsString('Відхилена '.$s, $rows('filters[status][comparison]==&filters[status][value]=new'));
        self::assertStringContainsString('Відхилена '.$s, $rows('filters[status][comparison]==&filters[status][value]=rejected'));
        self::assertStringNotContainsString('Нова '.$s, $rows('filters[status][comparison]==&filters[status][value]=rejected'));

        $crawler = $this->client->request('GET', $this->admin.'/wholesale-application/'.$application->getId());
        $form = $crawler->filter('form.wholesale-approve')->form();
        $this->client->request('POST', $form->getUri(), ['decision' => 'approve', 'group' => (string) $group->getId(), 'comment' => 'Вітаємо, знижка з понеділка', 'token' => $form->get('token')->getValue()]);
        self::assertResponseRedirects();
        $mails = $this->sentMails();
        self::assertCount(1, $mails);
        self::assertSame(SendWholesaleDecisionMail::APPROVED, $mails[0]->kind);
        self::assertSame('Вітаємо, знижка з понеділка', $mails[0]->reason, 'коментар при схваленні йде покупцю (рев\'ю #51)');
        $application = $this->application($pending);
        self::assertSame('Вітаємо, знижка з понеділка', $application->getReviewComment());
        $company = $application->getCompany();
        self::assertInstanceOf(Company::class, $company);
        self::assertSame($orphan->getId(), $company->getId(), 'сирітська компанія з тим самим кодом використана повторно');
        self::assertSame('Нова '.$s, $company->getName(), 'реквізити оновлено зі снепшоту заявки');
        self::assertSame('Контакт', $company->getContactName());
    }

    public function testRejectWithReasonAllowsNewApplication(): void
    {
        $email = self::email();
        $customer = $this->createCustomer($email);
        $this->client->loginUser($customer, 'site');
        $this->apply('ФОП Іваненко', '1234567890');
        $application = $this->application($customer);

        $this->loginAdmin();
        $crawler = $this->client->request('GET', $this->admin.'/wholesale-application/'.$application->getId());
        $form = $crawler->filter('form.wholesale-reject')->form();
        // без причини: відмова
        $this->client->request('POST', $form->getUri(), ['decision' => 'reject', 'comment' => '  ', 'token' => $form->get('token')->getValue()]);
        self::assertStringContainsString('причину', $this->client->followRedirect()->filter('.alert-danger')->text());
        self::assertSame(ApplicationStatus::New, $this->application($customer)->getStatus());

        $this->client->request('POST', $form->getUri(), ['decision' => 'reject', 'comment' => 'Не знайшли компанію в реєстрі', 'token' => $form->get('token')->getValue()]);
        self::assertResponseRedirects();
        $mails = $this->sentMails();
        self::assertCount(1, $mails);
        self::assertSame(SendWholesaleDecisionMail::REJECTED, $mails[0]->kind);
        self::assertSame('Не знайшли компанію в реєстрі', $mails[0]->reason);
        $application = $this->application($customer);
        self::assertSame(ApplicationStatus::Rejected, $application->getStatus());
        self::assertNull($application->getCustomer()->getCustomerGroup(), 'групи не призначено');
        self::assertSame(0, $this->em()->getRepository(Company::class)->count(['taxId' => '1234567890']), 'компанії не створено');

        // кабінет: причина й нова форма; повторна заявка приймається
        $crawler = $this->client->request('GET', $this->site.'/account/wholesale');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Не знайшли компанію', $crawler->filter('.wholesale-status')->text());
        self::assertCount(1, $crawler->filter('form.wholesale-form'));
        $this->apply('ФОП Іваненко', '1234567890');
        self::assertSame(2, $this->em()->getRepository(WholesaleApplication::class)->count(['customer' => $customer->getId()]));
        self::assertSame(ApplicationStatus::New, $this->application($customer)->getStatus());
    }

    public function testApplyIsRateLimitedPerIp(): void
    {
        // свій IP і скинутий лічильник: ліміт живе в кеші між прогонами і не має зачепити ні інші тести, ні повторний запуск
        $ip = '198.51.100.'.random_int(1, 254);
        $this->client->setServerParameter('REMOTE_ADDR', $ip);
        static::getContainer()->get('limiter.wholesale_apply_ip')->create($ip)->reset();
        $limit = 5;
        for ($i = 0; $i < $limit; ++$i) {
            $this->client->loginUser($this->createCustomer(self::email()), 'site');
            $this->apply('Компанія '.$i, '1000000'.$i);
            self::assertResponseRedirects($this->site.'/account/wholesale');
        }
        $customer = $this->createCustomer(self::email());
        $this->client->loginUser($customer, 'site');
        $this->apply('Компанія зайва', '10000099');
        self::assertResponseStatusCodeSame(429);
        self::assertSame(0, $this->em()->getRepository(WholesaleApplication::class)->count(['customer' => $customer->getId()]));
    }

    public function testAdminPagesNeedManager(): void
    {
        $this->client->request('GET', $this->admin.'/wholesale-application');
        self::assertResponseRedirects($this->admin.'/login');
    }

    private function apply(string $company, string $taxId): Crawler
    {
        $crawler = $this->client->request('GET', $this->site.'/account/wholesale');
        self::assertResponseIsSuccessful();

        return $this->client->submit($crawler->filter('form.wholesale-form')->form([
            'wholesale_application[companyName]' => $company,
            'wholesale_application[taxId]' => $taxId,
            'wholesale_application[contactName]' => 'Контакт',
        ]));
    }

    private function createCustomer(string $email): Customer
    {
        $customer = new Customer($email, 'Покупець', 'uk');
        $customer->verifyEmail(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, 'correct horse battery'));
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    private function group(): CustomerGroup
    {
        $group = new CustomerGroup('wholesale_'.bin2hex(random_bytes(2)), 'Опт '.bin2hex(random_bytes(2)));
        $this->em()->persist($group);
        $this->em()->flush();

        return $group;
    }

    private function loginAdmin(): User
    {
        $user = new User('manager-'.bin2hex(random_bytes(3)).'@example.test', 'Менеджер', UserRole::Manager);
        $user->setPasswordHash(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $this->em()->persist($user);
        $this->em()->flush();
        $this->client->loginUser($user, 'admin');

        return $user;
    }

    private function application(Customer $customer): WholesaleApplication
    {
        $this->em()->clear();
        $application = $this->em()->getRepository(WholesaleApplication::class)->findOneBy(['customer' => $customer->getId()], ['id' => 'DESC']);
        self::assertInstanceOf(WholesaleApplication::class, $application);

        return $application;
    }

    /** @return list<SendWholesaleDecisionMail> листи останнього запиту */
    private function sentMails(): array
    {
        $transport = static::getContainer()->get('messenger.transport.auth_mail');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(array_map(static fn ($e): object => $e->getMessage(), $transport->getSent()), static fn (object $m): bool => $m instanceof SendWholesaleDecisionMail));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private static function email(): string
    {
        return 'b2b-'.bin2hex(random_bytes(4)).'@example.test';
    }
}
