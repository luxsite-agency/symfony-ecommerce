<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site\Account;

use App\Core\Customer\Consent\Consents;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Enum\ConsentPurpose;
use App\Core\Customer\Message\SendCustomerAuthMail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * E12 PR-6, профіль: ім'я, телефон E.164, мова, згода на маркетинг у журналі; зміна пароля з поточним паролем гасить
 * інші сесії й remember_me; зміна email лише після листа на нову адресу, стара отримує сповіщення, зайнята адреса
 * не розкривається.
 */
final class ProfileTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $base;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects(false);
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->base = 'https://'.$host;
        $this->client->setServerParameter('HTTP_ORIGIN', $this->base);
        $this->client->setServerParameter('REMOTE_ADDR', '203.0.113.'.random_int(1, 254)); // ліміти листів з IP спільні на процес
    }

    public function testProfileSavesPhoneInE164LocaleAndMarketingConsent(): void
    {
        $customer = $this->createCustomer(self::email(), 'correct horse battery');
        $this->client->loginUser($customer, 'site');
        $crawler = $this->client->request('GET', $this->base.'/account/profile');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $consents = static::getContainer()->get(Consents::class);
        self::assertFalse($consents->isGranted($customer, ConsentPurpose::MarketingEmail), 'маркетинг за замовчуванням не поставлено');

        $this->client->submit($crawler->filter('form.profile-form')->form(['profile[name]' => 'Олена', 'profile[phone]' => 'abc']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('+380', $this->client->getCrawler()->filter('.form-error')->text());

        $form = $crawler->filter('form.profile-form')->form(['profile[name]' => 'Олена', 'profile[phone]' => '067 123 45 67', 'profile[locale]' => 'ru']);
        $marketing = $form['profile[marketing]'];
        self::assertInstanceOf(ChoiceFormField::class, $marketing);
        $marketing->tick();
        $this->client->submit($form);
        self::assertResponseRedirects($this->base.'/account/profile');
        $fresh = $this->fresh($customer);
        self::assertSame(['Олена', '+380671234567', 'ru'], [$fresh->getName(), $fresh->getPhone(), $fresh->getLocale()]);
        self::assertTrue($consents->isGranted($fresh, ConsentPurpose::MarketingEmail));
        $history = $consents->history($fresh);
        self::assertCount(1, $history);
        self::assertSame(['marketing_email', true, 'profile'], [$history[0]->getPurpose()->value, $history[0]->isGranted(), $history[0]->getSource()]);
        self::assertSame($consents->policyVersion(), $history[0]->getPolicyVersion());

        // зняти згоду — нова подія «відкликано», історія append-only
        $crawler = $this->client->request('GET', $this->base.'/account/profile');
        self::assertTrue($crawler->filter('input[name="profile[marketing]"]')->first()->matches('[checked]'));
        $form = $crawler->filter('form.profile-form')->form(['profile[name]' => 'Олена']);
        $marketing = $form['profile[marketing]'];
        self::assertInstanceOf(ChoiceFormField::class, $marketing);
        $marketing->untick();
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertFalse($consents->isGranted($this->fresh($customer), ConsentPurpose::MarketingEmail));
        self::assertCount(2, $consents->history($this->fresh($customer)));
    }

    public function testPasswordChangeNeedsCurrentPasswordAndLogsOutOtherDevices(): void
    {
        $email = self::email();
        $this->createCustomer($email, 'old password 123');
        // «інший пристрій»: сесія + remember_me
        $this->login($email, 'old password 123', remember: true);
        self::assertResponseRedirects($this->base.'/account/');
        $otherDevice = $this->client->getCookieJar()->all();
        $remember = $this->client->getCookieJar()->get('SHOP_REMEMBER', '/', parse_url($this->base, \PHP_URL_HOST));
        self::assertNotNull($remember);

        // цей пристрій
        $this->client->getCookieJar()->clear();
        $this->login($email, 'old password 123');
        $crawler = $this->client->request('GET', $this->base.'/account/profile/password');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form.password-form')->form(['password_change[current]' => 'wrong', 'password_change[password]' => 'brand new password']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('поточний пароль', $this->client->getCrawler()->filter('.form-error')->text());
        $this->client->submit($this->client->getCrawler()->filter('form.password-form')->form(['password_change[current]' => 'old password 123', 'password_change[password]' => 'brand new password']));
        self::assertResponseRedirects($this->base.'/account/profile');
        self::assertSame(SendCustomerAuthMail::PASSWORD_CHANGED, $this->sentMails()[0]->kind ?? null);
        $this->client->request('GET', $this->base.'/account/profile');
        self::assertResponseIsSuccessful('поточна сесія лишається');

        // інший пристрій: ні сесія, ні remember_me
        $this->client->getCookieJar()->clear();
        foreach ($otherDevice as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
        $this->client->request('GET', $this->base.'/account/');
        self::assertResponseRedirects($this->base.'/account/login');
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($remember);
        $this->client->request('GET', $this->base.'/account/');
        self::assertResponseRedirects($this->base.'/account/login', null, 'remember_me з підписом старого пароля');
        $this->login($email, 'brand new password');
        self::assertResponseRedirects($this->base.'/account/');
    }

    public function testEmailChangeTakesEffectOnlyAfterConfirmationOnNewAddress(): void
    {
        $old = self::email();
        $new = self::email();
        $customer = $this->createCustomer($old, 'correct horse battery');
        $this->client->loginUser($customer, 'site');
        $crawler = $this->client->request('GET', $this->base.'/account/profile/email');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form.email-form')->form(['email_change[email]' => $new, 'email_change[current]' => 'wrong']));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->sentMails(), 'без пароля листа нема');
        $this->client->submit($this->client->getCrawler()->filter('form.email-form')->form(['email_change[email]' => $new, 'email_change[current]' => 'correct horse battery']));
        self::assertResponseRedirects($this->base.'/account/profile');
        $mails = $this->sentMails();
        self::assertCount(1, $mails);
        self::assertSame([SendCustomerAuthMail::EMAIL_CHANGE, $new], [$mails[0]->kind, $mails[0]->email], 'лист іде на нову адресу');
        self::assertSame($old, $this->fresh($customer)->getEmail(), 'до підтвердження адреса стара');
        $url = $mails[0]->url;
        self::assertMatchesRegularExpression('#/account/email/[A-Za-z0-9_-]{43}$#', $url);

        // GET з листа — лише кнопка; POST — зміна, сповіщення на стару адресу, сесія жива
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($new, $crawler->filter('.email-confirm')->ancestors()->first()->text());
        self::assertSame($old, $this->fresh($customer)->getEmail());
        $this->client->submit($crawler->filter('form.email-confirm')->form());
        self::assertResponseRedirects($this->base.'/account/profile');
        self::assertSame($new, $this->fresh($customer)->getEmail());
        self::assertTrue($this->fresh($customer)->isEmailVerified());
        $notice = $this->sentMails();
        self::assertSame([SendCustomerAuthMail::EMAIL_CHANGED, $old], [$notice[0]->kind ?? null, $notice[0]->email ?? null], 'сповіщення на стару адресу');
        $this->client->request('GET', $this->base.'/account/profile');
        self::assertResponseIsSuccessful('сесія перелогінена з новою адресою');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(410, 'посилання одноразове');

        $revertUrl = $notice[0]->url;
        self::assertMatchesRegularExpression('#/account/email-revert/[A-Za-z0-9_-]{43}$#', $revertUrl);

        // вхід: стара адреса більше не діє, нова діє
        $this->client->request('POST', $this->base.'/account/logout', ['_csrf_token' => 'csrf-token']);
        $this->login($old, 'correct horse battery');
        self::assertResponseRedirects($this->base.'/account/login');
        $this->login($new, 'correct horse battery');
        self::assertResponseRedirects($this->base.'/account/');

        // «зловмисник» міняє адресу ще раз — посилання власника на стару скриньку живе далі (рев'ю #60, п. 3)
        $third = self::email();
        $crawler = $this->client->request('GET', $this->base.'/account/profile/email');
        $this->client->submit($crawler->filter('form.email-form')->form(['email_change[email]' => $third, 'email_change[current]' => 'correct horse battery']));
        $crawler = $this->client->request('GET', $this->sentMails()[0]->url);
        $this->client->submit($crawler->filter('form.email-confirm')->form());
        self::assertSame($third, $this->fresh($customer)->getEmail());
        // …і «застовплює» стару адресу неперевіреною реєстрацією з іншого клієнта (рев'ю #60 v2, M1)
        $placeholder = new Customer($old, '', 'uk');
        $this->em()->persist($placeholder);
        $this->em()->flush();
        $placeholderId = $placeholder->getId();

        // власник без сесії: GET — кнопка, POST — адреса повернута, заготовка зникла, пароль стерто, усі пристрої вийшли
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', $revertUrl);
        self::assertResponseIsSuccessful('публічна сторінка з листа');
        self::assertSame($third, $this->fresh($customer)->getEmail(), 'GET нічого не міняє');
        $this->client->submit($crawler->filter('form.email-revert')->form());
        self::assertResponseRedirects($this->base.'/account/password');
        $reverted = $this->fresh($customer);
        self::assertSame($old, $reverted->getEmail());
        self::assertNull($this->em()->find(Customer::class, $placeholderId), 'неперевірена заготовка з чужою адресою видалена');
        self::assertNull($reverted->getPassword(), 'вхід заблоковано до нового пароля');
        self::assertTrue($reverted->isActive());
        $this->client->request('GET', $revertUrl);
        self::assertResponseStatusCodeSame(410, 'одноразове');
        $this->login($third, 'correct horse battery');
        self::assertResponseRedirects($this->base.'/account/login');
        $this->login($old, 'correct horse battery');
        self::assertResponseRedirects($this->base.'/account/login', null, 'старий пароль теж не діє');
        // новий пароль — лише через «забули пароль» на стару адресу
        $crawler = $this->client->request('GET', $this->base.'/account/password');
        $this->client->submit($crawler->filter('form.account-form')->form(['email_request[email]' => $old]));
        self::assertSame([SendCustomerAuthMail::RESET, $old], [$this->sentMails()[0]->kind ?? null, $this->sentMails()[0]->email ?? null]);
    }

    /** Рев'ю #60 v2, M2: повторна автентифікація паролем має ліміт, як і вхід; 6-та спроба — 429 навіть з правильним паролем. */
    public function testReauthIsRateLimitedPerCustomer(): void
    {
        $customer = $this->createCustomer(self::email(), 'correct horse battery');
        $this->client->loginUser($customer, 'site');
        $crawler = $this->client->request('GET', $this->base.'/account/profile/password');
        for ($i = 0; $i < 5; ++$i) {
            $this->client->submit($crawler->filter('form.password-form')->form(['password_change[current]' => 'wrong '.$i, 'password_change[password]' => 'another strong pass']));
            self::assertResponseStatusCodeSame(422);
        }
        $this->client->submit($crawler->filter('form.password-form')->form(['password_change[current]' => 'correct horse battery', 'password_change[password]' => 'another strong pass']));
        self::assertResponseStatusCodeSame(429);
        $this->client->request('POST', $this->base.'/account/logout', ['_csrf_token' => 'csrf-token']);
        $this->login($customer->getEmail(), 'another strong pass');
        self::assertResponseRedirects($this->base.'/account/login', null, 'пароль не змінено');
        // ліміт спільний для всіх дій з паролем: видалення теж 429
        $this->client->loginUser($this->fresh($customer), 'site');
        $crawler = $this->client->request('GET', $this->base.'/account/privacy/delete');
        $this->client->submit($crawler->filter('form.delete-form')->form(['reauth[current]' => 'correct horse battery']));
        self::assertResponseStatusCodeSame(429);
        self::assertTrue($this->fresh($customer)->isActive());
    }

    /** Рев'ю #60 v2, L3: неперевірена заготовка чужої адреси не заважає власнику акаунта змінити адресу на неї. */
    public function testEmailChangeToUnverifiedPlaceholderRemovesIt(): void
    {
        $target = self::email();
        $placeholder = new Customer($target, '', 'uk');
        $this->em()->persist($placeholder);
        $this->em()->flush();
        $placeholderId = $placeholder->getId();
        $customer = $this->createCustomer(self::email(), 'correct horse battery');
        $this->client->loginUser($customer, 'site');
        $crawler = $this->client->request('GET', $this->base.'/account/profile/email');
        $this->client->submit($crawler->filter('form.email-form')->form(['email_change[email]' => $target, 'email_change[current]' => 'correct horse battery']));
        self::assertResponseRedirects($this->base.'/account/profile');
        $mails = $this->sentMails();
        self::assertSame([SendCustomerAuthMail::EMAIL_CHANGE, $target], [$mails[0]->kind ?? null, $mails[0]->email ?? null], 'лист іде, адреса не «зайнята»');
        $crawler = $this->client->request('GET', $mails[0]->url);
        $this->client->submit($crawler->filter('form.email-confirm')->form());
        self::assertResponseRedirects($this->base.'/account/profile');
        self::assertSame($target, $this->fresh($customer)->getEmail());
        self::assertNull($this->em()->find(Customer::class, $placeholderId), 'заготовку прибрано в тій самій транзакції');
    }

    public function testEmailChangeToTakenAddressSendsNothingButAnswersTheSame(): void
    {
        $taken = self::email();
        $this->createCustomer($taken, 'other password 123');
        $customer = $this->createCustomer(self::email(), 'correct horse battery');
        $this->client->loginUser($customer, 'site');
        $crawler = $this->client->request('GET', $this->base.'/account/profile/email');
        $this->client->submit($crawler->filter('form.email-form')->form(['email_change[email]' => strtoupper($taken), 'email_change[current]' => 'correct horse battery']));
        self::assertResponseRedirects($this->base.'/account/profile');
        self::assertCount(0, $this->sentMails(), 'на зайняту адресу лист не йде');
        self::assertStringContainsString('Перевірте нову пошту', $this->client->followRedirect()->filter('.flash-success')->text(), 'відповідь така сама, як для вільної');

        // чужий токен: інший покупець з посиланням нічого не змінить
        $crawler = $this->client->request('GET', $this->base.'/account/profile/email');
        $this->client->submit($crawler->filter('form.email-form')->form(['email_change[email]' => self::email(), 'email_change[current]' => 'correct horse battery']));
        $url = $this->sentMails()[0]->url;
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($this->fresh($this->createCustomer(self::email(), 'third password 123')), 'site');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(410);
        $this->client->request('POST', $url, ['_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(410);
    }

    private function login(string $email, string $password, bool $remember = false): void
    {
        $crawler = $this->client->request('GET', $this->base.'/account/login');
        $form = $crawler->filter('form.account-form')->form(['email' => $email, 'password' => $password]);
        if ($remember) {
            $checkbox = $form['_remember_me'];
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            $checkbox->tick();
        }
        $this->client->submit($form);
    }

    private function createCustomer(string $email, string $password): Customer
    {
        $customer = new Customer($email, 'Покупець', 'uk');
        $customer->verifyEmail(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, $password));
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    private function fresh(Customer $customer): Customer
    {
        $this->em()->clear();

        return $this->em()->find(Customer::class, $customer->getId()) ?? self::fail('покупця нема');
    }

    /** @return list<SendCustomerAuthMail> листи останнього запиту */
    private function sentMails(): array
    {
        $transport = static::getContainer()->get('messenger.transport.auth_mail');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(array_map(static fn ($e): object => $e->getMessage(), $transport->getSent()), static fn (object $m): bool => $m instanceof SendCustomerAuthMail));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private static function email(): string
    {
        return 'prof-'.bin2hex(random_bytes(4)).'@example.test';
    }
}
