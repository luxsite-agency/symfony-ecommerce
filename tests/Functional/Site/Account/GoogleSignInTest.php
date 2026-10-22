<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site\Account;

use App\Core\Customer\Consent\Consents;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Enum\ConsentPurpose;
use App\Core\Customer\Export\CustomerDataExport;
use App\Core\Customer\Google\OidcException;
use App\Core\Customer\Message\SendCustomerAuthMail;
use App\Core\Demo\DemoAccounts;
use App\Core\Security\Entity\User;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Tests\Support\Google\FakeGoogle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Вхід через Google (13.10.2026) з фейковим OIDC-провайдером (tests/Support/Google/FakeGoogle, when@test): новий покупець,
 * наявний підтверджений, неперевірена заготовка, демо й адмін, підробки state/nonce/aud/iss/exp/підпису, open redirect,
 * прив'язка й відв'язка з кабінету, кнопка без ключів, ліміт. Ядро не перезавантажується між запитами (disableReboot):
 * фейк тримає видані коди і ключ підпису в пам'яті процесу.
 */
final class GoogleSignInTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $base;
    private FakeGoogle $google;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->base = 'https://'.$host;
        // окремий IP на тест: ліміт google_sign_in_ip (30/15 хв) спільний на процес, /24 з 254 адрес давав колізії у повному прогоні
        $this->client->setServerParameter('REMOTE_ADDR', '100.64.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->client->setServerParameter('HTTP_ORIGIN', $this->base);
        // за id клієнта (у dev-контейнері, яким користується PHPStan, FakeGoogle нема): у test це аліас на фейк
        $google = static::getContainer()->get('google_oidc.http_client');
        self::assertInstanceOf(FakeGoogle::class, $google);
        $this->google = $google;
    }

    public function testNewCustomerSignsUpThenLogsInAgain(): void
    {
        $email = self::email();
        $flow = $this->start('/');
        self::assertSame(FakeGoogle::CLIENT_ID, $flow['client_id']);
        self::assertSame(FakeGoogle::REDIRECT_URI, $flow['redirect_uri']);
        self::assertSame('code', $flow['response_type']);
        self::assertSame('S256', $flow['code_challenge_method']);
        self::assertSame('openid email profile', $flow['scope']);
        self::assertSame(32, \strlen($flow['state']));
        self::assertNotSame($flow['state'], $flow['nonce']);

        $code = $this->google->grant(['sub' => 'g-'.$email, 'email' => strtoupper($email), 'name' => 'Ганна Google'], $flow['nonce'], $flow['code_challenge']);
        $this->finishAtCallback($code, $flow['state']);
        self::assertResponseRedirects('/');
        self::assertSame('authorization_code', $this->google->requests[0]['body']['grant_type'] ?? null);
        self::assertStringNotContainsString('client_secret', http_build_query([]), 'секрет не в журналі тесту');

        $customer = $this->customer($email);
        self::assertTrue($customer->isEmailVerified());
        self::assertNull($customer->getPassword(), 'без пароля: вхід через Google або «встановити пароль»');
        self::assertSame('g-'.$email, $customer->getGoogleSub());
        self::assertSame('Ганна Google', $customer->getName());
        self::assertSame('uk', $customer->getLocale());
        self::assertNotNull($customer->getLastLoginAt());
        self::assertTrue(static::getContainer()->get(Consents::class)->isGranted($customer, ConsentPurpose::PdProcessing), 'згода на ПД записана з джерелом google');

        // сесія справжня: кабінет відкривається, профіль показує «Google підключено» без кнопки відключення (пароля нема)
        $crawler = $this->client->request('GET', $this->base.'/account/profile');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Google підключено', $crawler->filter('.google-link')->text());
        self::assertCount(0, $crawler->filter('form.google-unlink'));
        self::assertStringContainsString('встановіть пароль', $crawler->filter('.google-link')->text());

        // відключити без пароля не можна
        $this->client->request('POST', $this->base.'/account/google/unlink', ['_token' => 'csrf-token']);
        self::assertResponseRedirects($this->base.'/account/profile');
        self::assertSame('g-'.$email, $this->customer($email)->getGoogleSub());

        // вихід, повторний вхід через Google — той самий покупець, без дублювання, навіть з іншою адресою в Google
        $this->logout();
        $flow = $this->start(null);
        $code = $this->google->grant(['sub' => 'g-'.$email, 'email' => 'changed-'.$email, 'name' => 'Інше ім’я'], $flow['nonce'], $flow['code_challenge']);
        $this->finishAtCallback($code, $flow['state']);
        self::assertResponseRedirects('/account/');
        $again = $this->customer($email);
        self::assertSame($customer->getId(), $again->getId());
        self::assertSame($email, $again->getEmail(), 'адреса кабінету не змінюється за Google');
        self::assertSame('Ганна Google', $again->getName());

        // посилання для входу з листа працює і без пароля (спосіб входу є — Google)
        $this->logout();
        $this->client->request('GET', $this->base.'/account/login-link');
        $this->client->submit($this->client->getCrawler()->filter('form.account-form')->form(['email_request[email]' => $email]));
        self::assertResponseRedirects($this->base.'/account/login');
        $mails = $this->sentMails();
        self::assertCount(1, $mails);
        self::assertSame(SendCustomerAuthMail::LOGIN_LINK, $mails[0]->kind);
    }

    public function testGa4EventsAreQueuedForNextTrackedPage(): void
    {
        $settings = static::getContainer()->get(Settings::class);
        $settings->invalidate();
        $settings->set(SettingKeys::TAGS_ON_DEV, '1');
        $settings->set(SettingKeys::GA4_MEASUREMENT_ID, 'G-ABC1234567');
        try {
            $email = self::email();
            $flow = $this->start('/');
            $this->finishAtCallback($this->google->grant(['sub' => 'g-'.$email, 'email' => $email, 'name' => 'Подія'], $flow['nonce'], $flow['code_challenge']), $flow['state']);
            $crawler = $this->client->followRedirect();
            self::assertResponseIsSuccessful();
            $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 8, \JSON_THROW_ON_ERROR);
            self::assertContains(['event' => 'sign_up', 'method' => 'google'], $events, 'новий покупець → sign_up method=google на першій сторінці поза кабінетом');

            $this->logout();
            $flow = $this->start('/');
            $this->finishAtCallback($this->google->grant(['sub' => 'g-'.$email, 'email' => $email], $flow['nonce'], $flow['code_challenge']), $flow['state']);
            $crawler = $this->client->followRedirect();
            $events = json_decode($crawler->filter('script[data-analytics-events]')->text(), true, 8, \JSON_THROW_ON_ERROR);
            self::assertContains(['event' => 'login', 'method' => 'google'], $events);
            self::assertNotContains(['event' => 'sign_up', 'method' => 'google'], $events);
        } finally {
            $settings->set(SettingKeys::TAGS_ON_DEV, '0');
            $settings->set(SettingKeys::GA4_MEASUREMENT_ID, '');
            $settings->invalidate();
        }
    }

    public function testExistingVerifiedCustomerIsLinkedByVerifiedEmailAndCanUnlink(): void
    {
        $email = self::email();
        $customer = $this->createCustomer($email, 'correct horse battery');
        $hash = $customer->getPassword();

        $flow = $this->start(null);
        $this->finishAtCallback($this->google->grant(['sub' => 'sub-'.$email, 'email' => $email, 'name' => 'Не це ім’я'], $flow['nonce'], $flow['code_challenge']), $flow['state']);
        self::assertResponseRedirects('/account/');
        $linked = $this->customer($email);
        self::assertSame('sub-'.$email, $linked->getGoogleSub());
        self::assertSame($hash, $linked->getPassword(), 'пароль не чіпається');
        self::assertSame('Покупець', $linked->getName(), 'ім’я наявного покупця не перезаписується');
        self::assertSame($email, $linked->getGoogleEmail());
        // рев'ю #74, M1: прив'язка при вході — лист з адресою акаунта Google; прив'язка не є невидимим «запасним входом»
        $mails = $this->sentMails();
        self::assertSame([SendCustomerAuthMail::GOOGLE_LINKED], array_map(static fn (SendCustomerAuthMail $m): string => $m->kind, $mails));
        self::assertSame($email, $mails[0]->detail);
        self::assertSame($email, $mails[0]->email);

        // профіль: «Google підключено: <адреса>, з <дата>» + відключити (пароль є)
        $crawler = $this->client->request('GET', $this->base.'/account/profile');
        self::assertStringContainsString('Google підключено: '.$email.', з ', $crawler->filter('.google-link')->text());
        self::assertCount(1, $crawler->filter('form.google-unlink'));
        $this->client->submit($crawler->filter('form.google-unlink')->form());
        self::assertResponseRedirects($this->base.'/account/profile');
        self::assertNull($this->customer($email)->getGoogleSub());
        self::assertNull($this->customer($email)->getGoogleEmail());
        $mails = $this->sentMails(); // транспорт скидається між запитами: дивимось до followRedirect
        self::assertSame(SendCustomerAuthMail::GOOGLE_UNLINKED, $mails[0]->kind ?? null);
        self::assertSame($email, $mails[0]->detail ?? null, 'лист про відключення називає адресу, яку відключили');
        self::assertStringContainsString('Google відключено', $this->client->followRedirect()->filter('.flash-success')->text());

        // та сама адреса, але вже прив'язана до ІНШОГО акаунта Google — другий не прив'язується
        $this->logout();
        $flow = $this->start(null);
        $this->finishAtCallback($this->google->grant(['sub' => 'sub-'.$email, 'email' => $email], $flow['nonce'], $flow['code_challenge']), $flow['state']);
        $this->logout();
        $flow = $this->start(null);
        $this->finishAtCallback($this->google->grant(['sub' => 'other-'.$email, 'email' => $email], $flow['nonce'], $flow['code_challenge']), $flow['state']);
        $this->assertLoginFailed('вже підключено інший акаунт Google');
        self::assertSame('sub-'.$email, $this->customer($email)->getGoogleSub());
    }

    public function testUnverifiedRegistrationPlaceholderIsTakenOverAndItsTokensRevoked(): void
    {
        $email = self::email();
        $this->register($email);
        $verifyUrl = $this->mailUrl(SendCustomerAuthMail::VERIFY);
        self::assertFalse($this->customer($email)->isEmailVerified());

        $flow = $this->start('/ru/');
        $this->finishAtCallback($this->google->grant(['sub' => 'ph-'.$email, 'email' => $email, 'name' => 'Власник Скриньки'], $flow['nonce'], $flow['code_challenge']), $flow['state']);
        self::assertResponseRedirects('/ru/');
        $customer = $this->customer($email);
        self::assertTrue($customer->isEmailVerified());
        self::assertSame('Власник Скриньки', $customer->getName());
        self::assertSame('ph-'.$email, $customer->getGoogleSub());
        self::assertNull($customer->getPassword());

        // посилання підтвердження з листа (могло бути в того, хто «застовпив» адресу) більше не діє
        $this->client->request('GET', $verifyUrl);
        self::assertResponseStatusCodeSame(410);
        $this->client->request('POST', $verifyUrl, ['verify_email' => ['name' => 'Зловмисник', 'password' => 'attacker password 1', '_token' => 'x']]);
        self::assertResponseStatusCodeSame(410);
        self::assertNull($this->customer($email)->getPassword());
    }

    public function testUnverifiedGoogleEmailDemoAndAdminAreRefused(): void
    {
        $email = self::email();
        $flow = $this->start(null);
        $this->finishAtCallback($this->google->grant(['sub' => 'u-'.$email, 'email' => $email, 'email_verified' => false], $flow['nonce'], $flow['code_challenge']), $flow['state']);
        $this->assertLoginFailed('не підтвердив вашу адресу');
        self::assertNull($this->em()->getRepository(Customer::class)->findOneBy(['email' => $email]), 'покупець не створюється');

        $flow = $this->start(null);
        $this->finishAtCallback($this->google->grant(['sub' => 'demo-sub', 'email' => DemoAccounts::RETAIL], $flow['nonce'], $flow['code_challenge']), $flow['state']);
        $this->assertLoginFailed('Демо-акаунти');

        $adminEmail = 'manager-'.bin2hex(random_bytes(3)).'@example.test';
        $this->em()->persist(new User($adminEmail, 'Менеджер'));
        $this->em()->flush();
        $flow = $this->start(null);
        $this->finishAtCallback($this->google->grant(['sub' => 'admin-sub', 'email' => $adminEmail], $flow['nonce'], $flow['code_challenge']), $flow['state']);
        $this->assertLoginFailed('не може входити на сайт через Google');
        self::assertNull($this->em()->getRepository(Customer::class)->findOneBy(['email' => $adminEmail]));

        // вимкнений покупець
        $disabled = $this->createCustomer(self::email(), 'correct horse battery');
        $disabled->setActive(false);
        $this->em()->flush();
        $flow = $this->start(null);
        $this->finishAtCallback($this->google->grant(['sub' => 'dis-sub', 'email' => $disabled->getEmail()], $flow['nonce'], $flow['code_challenge']), $flow['state']);
        $this->assertLoginFailed('Акаунт вимкнено');
    }

    public function testForgedStateNonceAudIssExpAndSignatureAreRejected(): void
    {
        $email = self::email();
        $identity = ['sub' => 'f-'.$email, 'email' => $email];

        // чужий state (CSRF на callback): прохід гаситься, код не обмінюється
        $flow = $this->start(null);
        $code = $this->google->grant($identity, $flow['nonce'], $flow['code_challenge']);
        $requests = \count($this->google->requests);
        $this->finishAtCallback($code, 'forged-state');
        $this->assertLoginFailed('Сесія входу застаріла');
        self::assertCount($requests, $this->google->requests, 'без обміну коду');
        $this->finishAtCallback($code, $flow['state']);
        $this->assertLoginFailed('Сесія входу застаріла', 'один callback на один старт');

        // підроблені claims: nonce, aud, iss, exp; підпис невідомим ключем; невірний code_verifier (PKCE)
        foreach ([
            'nonce' => ['nonce' => 'other-nonce'],
            'aud' => ['aud' => 'another-client.apps.googleusercontent.com'],
            'iss' => ['iss' => 'https://accounts.example.com'],
            'exp' => ['exp' => time() - 3600],
        ] as $case => $override) {
            $flow = $this->start(null);
            $this->finishAtCallback($this->google->grant($identity, $flow['nonce'], $flow['code_challenge'], $override), $flow['state']);
            $this->assertLoginFailed('Google не підтвердив вхід', $case);
        }
        $flow = $this->start(null);
        $this->finishAtCallback($this->google->grant($identity, $flow['nonce'], $flow['code_challenge'], [], unknownKey: true), $flow['state']);
        $this->assertLoginFailed('Google не підтвердив вхід', 'підпис');
        $flow = $this->start(null);
        $this->finishAtCallback($this->google->grant($identity, $flow['nonce'], 'not-the-challenge'), $flow['state']);
        $this->assertLoginFailed('Google не підтвердив вхід', 'PKCE');

        // відмова користувача на екрані Google і недоступний token endpoint
        $flow = $this->start(null);
        $this->client->request('GET', $this->base.'/account/google/callback?state='.$flow['state'].'&error=access_denied');
        $this->assertLoginFailed('скасовано');
        $flow = $this->start(null);
        $this->google->tokenEndpointDown = true;
        try {
            $this->finishAtCallback($this->google->grant($identity, $flow['nonce'], $flow['code_challenge']), $flow['state']);
        } finally {
            $this->google->tokenEndpointDown = false;
        }
        $this->assertLoginFailed('Google не підтвердив вхід', 'token endpoint 503');
        self::assertNull($this->em()->getRepository(Customer::class)->findOneBy(['email' => $email]), 'жодна підробка не створила покупця');

        // справжній вхід після всього цього працює (ліміт 30/15 хв не вичерпано)
        $flow = $this->start(null);
        $this->finishAtCallback($this->google->grant($identity, $flow['nonce'], $flow['code_challenge']), $flow['state']);
        self::assertResponseRedirects('/account/');
    }

    public function testReturnPathOnlyRelative(): void
    {
        $email = self::email();
        foreach (['https://evil.example/x', '//evil.example/x', '/\\evil.example', "/cart/\r\nX: y", '/account/google/start', '/account/logout'] as $bad) {
            $flow = $this->start($bad);
            $this->finishAtCallback($this->google->grant(['sub' => 'r-'.$email, 'email' => $email], $flow['nonce'], $flow['code_challenge']), $flow['state']);
            self::assertResponseRedirects('/account/', null, 'небезпечний next «'.$bad.'» → кабінет');
            $this->logout();
        }
        $flow = $this->start('/ru/cart/?x=1');
        $this->finishAtCallback($this->google->grant(['sub' => 'r-'.$email, 'email' => $email], $flow['nonce'], $flow['code_challenge']), $flow['state']);
        self::assertResponseRedirects('/ru/cart/?x=1');
    }

    public function testLinkFromProfileNeedsCurrentPasswordAndUniqueGoogleAccount(): void
    {
        $email = self::email();
        $customer = $this->createCustomer($email, 'correct horse battery');
        $this->client->loginUser($customer, 'site');

        $crawler = $this->client->request('GET', $this->base.'/account/profile');
        self::assertStringContainsString('Google не підключено', $crawler->filter('.google-link')->text());
        $crawler = $this->client->request('GET', $this->base.'/account/google/link');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form.google-link-form')->form(['reauth[current]' => 'wrong password']));
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->customer($email)->getGoogleSub());

        $this->client->submit($this->client->getCrawler()->filter('form.google-link-form')->form(['reauth[current]' => 'correct horse battery']));
        $flow = $this->parseStart();
        self::assertSame($email, $flow['login_hint'] ?? null);

        // sub уже за іншим покупцем — відмова
        $other = $this->createCustomer(self::email(), 'another password 1');
        $other->linkGoogle('taken-sub', 'taken@example.test');
        $this->em()->flush();
        $this->client->request('GET', $this->base.'/account/google/callback?state='.$flow['state'].'&code='.$this->google->grant(['sub' => 'taken-sub', 'email' => 'whatever-'.$email], $flow['nonce'], $flow['code_challenge']));
        self::assertResponseRedirects($this->base.'/account/profile');
        self::assertStringContainsString('підключено до іншого кабінету', $this->client->followRedirect()->filter('.flash-error')->text());
        self::assertNull($this->customer($email)->getGoogleSub());

        // прив'язка: email у Google може відрізнятись від адреси кабінету
        $this->client->request('GET', $this->base.'/account/google/link');
        $this->client->submit($this->client->getCrawler()->filter('form.google-link-form')->form(['reauth[current]' => 'correct horse battery']));
        $flow = $this->parseStart();
        $this->client->request('GET', $this->base.'/account/google/callback?state='.$flow['state'].'&code='.$this->google->grant(['sub' => 'mine-sub', 'email' => 'other-'.$email], $flow['nonce'], $flow['code_challenge']));
        self::assertResponseRedirects($this->base.'/account/profile');
        $linkedMail = $this->sentMails()[0] ?? null;
        self::assertInstanceOf(SendCustomerAuthMail::class, $linkedMail, 'лист про прив\'язку з кабінету (рев\'ю #74, M1)');
        self::assertSame(SendCustomerAuthMail::GOOGLE_LINKED, $linkedMail->kind);
        self::assertSame($email, $linkedMail->email, 'на адресу кабінету');
        self::assertSame('other-'.$email, $linkedMail->detail, 'з адресою підключеного акаунта Google');
        self::assertStringContainsString('Google підключено', $this->client->followRedirect()->filter('.flash-success')->text());
        self::assertSame('mine-sub', $this->customer($email)->getGoogleSub());
        self::assertSame($email, $this->customer($email)->getEmail());
        self::assertSame('other-'.$email, $this->customer($email)->getGoogleEmail());
        self::assertStringContainsString('Google підключено: other-'.$email, $this->client->request('GET', $this->base.'/account/profile')->filter('.google-link')->text());

        // після прив'язки сторінка link веде назад у профіль; вхід через Google потім — за sub
        $this->client->request('GET', $this->base.'/account/google/link');
        self::assertResponseRedirects($this->base.'/account/profile');
        $this->logout();
        $flow = $this->start(null);
        $this->finishAtCallback($this->google->grant(['sub' => 'mine-sub', 'email' => 'other-'.$email], $flow['nonce'], $flow['code_challenge']), $flow['state']);
        self::assertResponseRedirects('/account/');
        self::assertStringContainsString('Покупець', $this->client->followRedirect()->filter('main, body')->text());
    }

    public function testEmailRevertUnlinksGoogle(): void
    {
        $email = self::email();
        $customer = $this->createCustomer($email, 'correct horse battery');
        $customer->linkGoogle('revert-sub', 'revert@example.test');
        $this->em()->flush();
        $customer->lockOut();
        self::assertNull($customer->getGoogleSub(), 'повернення старої адреси (lockOut) відв’язує Google разом зі стиранням пароля');
        self::assertFalse($customer->hasLoginMethod());
        $this->em()->flush();
        // паролем і старим sub не увійти; власник скриньки (Google підтвердив адресу) входить і прив'язує Google знову —
        // це той самий доказ володіння адресою, що й «забули пароль»
        $this->client->request('GET', $this->base.'/account/login');
        $this->client->submit($this->client->getCrawler()->filter('form.account-form')->form(['email' => $email, 'password' => 'correct horse battery']));
        self::assertResponseRedirects($this->base.'/account/login');
        $flow = $this->start(null);
        $this->finishAtCallback($this->google->grant(['sub' => 'new-sub', 'email' => $email], $flow['nonce'], $flow['code_challenge']), $flow['state']);
        self::assertResponseRedirects('/account/');
        self::assertSame('new-sub', $this->customer($email)->getGoogleSub());
    }

    /** Рев'ю #74, L1: покупець лише з Google не має пароля — reauth-сторінки кажуть «спершу встановіть пароль» замість форми; L4: експорт містить Google. */
    public function testGoogleOnlyCustomerIsToldToSetPasswordBeforeReauthActionsAndPasswordMailNamesGoogle(): void
    {
        $email = self::email();
        $flow = $this->start(null);
        $this->finishAtCallback($this->google->grant(['sub' => 'only-'.$email, 'email' => $email, 'name' => 'Лише Google'], $flow['nonce'], $flow['code_challenge']), $flow['state']);
        self::assertResponseRedirects('/account/');
        foreach (['/account/privacy/export' => 'form.export-form', '/account/privacy/delete' => 'form.delete-form', '/account/profile/email' => 'form.email-form', '/account/profile/password' => 'form.password-form'] as $path => $form) {
            $crawler = $this->client->request('GET', $this->base.$path);
            self::assertResponseIsSuccessful($path);
            self::assertCount(0, $crawler->filter($form), $path.': форми з паролем нема');
            self::assertStringContainsString('встановіть пароль', $crawler->filter('.no-password')->text(), $path);
            self::assertStringEndsWith('/account/password', (string) $crawler->filter('.no-password a')->attr('href'), $path);
        }
        self::assertSame($email, static::getContainer()->get(CustomerDataExport::class)->build($this->customer($email))['google']['email'] ?? null, 'експорт ПД містить прив\'язку Google (L4)');

        // «забули пароль» — єдиний шлях до пароля; лист про зміну пароля називає підключений Google (M1)
        $this->logout();
        $this->client->request('GET', $this->base.'/account/password');
        $this->client->submit($this->client->getCrawler()->filter('form.account-form')->form(['email_request[email]' => $email]));
        $crawler = $this->client->request('GET', $this->mailUrl(SendCustomerAuthMail::RESET));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form.account-form')->form(['new_password[password]' => 'brand new password']));
        self::assertResponseRedirects('/account/');
        $changed = $this->sentMails();
        $changed = $changed[\count($changed) - 1];
        self::assertSame(SendCustomerAuthMail::PASSWORD_CHANGED, $changed->kind);
        self::assertSame($email, $changed->detail, 'у листі — адреса підключеного акаунта Google');
        self::assertCount(1, $this->client->request('GET', $this->base.'/account/privacy/export')->filter('form.export-form'), 'з паролем форма повертається');
    }

    /** Рев'ю #74, L2: ім'я з Google довше за 120 символів обрізається, а не 500; L3: ?error= від «Google» — лише відомий код. */
    public function testLongGoogleNameIsTruncatedAndUnknownProviderErrorIsGeneric(): void
    {
        $email = self::email();
        $flow = $this->start(null);
        $this->finishAtCallback($this->google->grant(['sub' => 'long-'.$email, 'email' => $email, 'name' => str_repeat('Ім', 100)], $flow['nonce'], $flow['code_challenge']), $flow['state']);
        self::assertResponseRedirects('/account/');
        self::assertSame(Customer::NAME_MAX, mb_strlen($this->customer($email)->getName()));

        $this->logout();
        $flow = $this->start(null);
        $this->client->request('GET', $this->base.'/account/google/callback?state='.$flow['state'].'&error='.rawurlencode(str_repeat('<junk>', 200)));
        $this->assertLoginFailed('Google не підтвердив вхід');
        self::assertSame('other', OidcException::providerReason(str_repeat('x', 500)));
        self::assertSame('cancelled', OidcException::providerReason('access_denied'));
        self::assertSame('temporarily_unavailable', OidcException::providerReason('temporarily_unavailable'));
    }

    /** Рев'ю #74, L5: прохід входу, почату анонімно, дожив до callback уже залогіненого (інша вкладка) — сесію не підміняємо. */
    public function testLoginCallbackOfAlreadyLoggedInCustomerIsIgnored(): void
    {
        $email = self::email();
        $this->createCustomer($email, 'correct horse battery');
        $flow = $this->start('/ru/');
        // та сама сесія входить паролем (вкладка 2); міграція сесії при вході зберігає атрибути — прохід живий
        $crawler = $this->client->request('GET', $this->base.'/account/login');
        $this->client->submit($crawler->filter('form.account-form')->form(['email' => $email, 'password' => 'correct horse battery']));
        self::assertResponseRedirects($this->base.'/account/');

        $otherEmail = self::email();
        $requests = \count($this->google->requests);
        $this->finishAtCallback($this->google->grant(['sub' => 'g-'.$otherEmail, 'email' => $otherEmail], $flow['nonce'], $flow['code_challenge']), $flow['state']);
        self::assertResponseRedirects($this->base.'/ru/', null, 'ведемо туди, куди просив старт');
        self::assertCount($requests, $this->google->requests, 'код не обмінюється');
        self::assertStringContainsString($email, $this->client->request('GET', $this->base.'/account/profile')->filter('main, body')->text(), 'сесія лишилась сесією покупця з паролем');
        self::assertNull($this->em()->getRepository(Customer::class)->findOneBy(['email' => $otherEmail]), 'другий покупець не створений');
        self::assertNull($this->customer($email)->getGoogleSub());
    }

    public function testWithoutKeysButtonIsHiddenAndRoutesAre404(): void
    {
        $this->client->enableReboot(); // GoogleSignInConfig читає env при створенні: свіже ядро на кожен запит
        $customer = $this->createCustomer(self::email(), 'correct horse battery');
        $crawler = $this->client->request('GET', $this->base.'/account/login');
        self::assertCount(1, $crawler->filter('a.btn-google'), 'з ключами кнопка є');
        self::assertStringContainsString('/account/google/start?next=/ru/', (string) $this->client->request('GET', $this->base.'/account/login?next=/ru/')->filter('a.btn-google')->attr('href'));
        self::assertCount(1, $this->client->request('GET', $this->base.'/account/register')->filter('a.btn-google'));

        $saved = [$_SERVER['GOOGLE_OAUTH_CLIENT_ID'] ?? null, $_ENV['GOOGLE_OAUTH_CLIENT_ID'] ?? null];
        $_SERVER['GOOGLE_OAUTH_CLIENT_ID'] = $_ENV['GOOGLE_OAUTH_CLIENT_ID'] = '';
        try {
            self::assertCount(0, $this->client->request('GET', $this->base.'/account/login')->filter('a.btn-google'));
            self::assertCount(0, $this->client->request('GET', $this->base.'/account/register')->filter('a.btn-google'));
            $this->client->request('GET', $this->base.'/account/google/start');
            self::assertResponseStatusCodeSame(404);
            $this->client->request('GET', $this->base.'/account/google/callback?state=x&code=y');
            self::assertResponseStatusCodeSame(404);
            $this->client->loginUser($customer, 'site');
            self::assertCount(0, $this->client->request('GET', $this->base.'/account/profile')->filter('.google-link'));
            $this->client->request('GET', $this->base.'/account/google/link');
            self::assertResponseStatusCodeSame(404);
        } finally {
            [$_SERVER['GOOGLE_OAUTH_CLIENT_ID'], $_ENV['GOOGLE_OAUTH_CLIENT_ID']] = $saved;
        }
    }

    public function testCallbackIsRateLimitedPerIp(): void
    {
        $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.'.random_int(1, 254));
        for ($i = 0; $i < 30; ++$i) {
            $this->client->request('GET', $this->base.'/account/google/callback?state=bogus&code=x');
            self::assertResponseRedirects($this->base.'/account/login');
        }
        $this->client->request('GET', $this->base.'/account/google/callback?state=bogus&code=x');
        self::assertResponseRedirects($this->base.'/account/login');
        self::assertStringContainsString('Забагато спроб', $this->client->followRedirect()->filter('.flash-error')->last()->text(), 'останній флеш (попередні 30 — «сесія застаріла»)');
        $this->client->request('GET', $this->base.'/account/google/start');
        self::assertResponseRedirects($this->base.'/account/login', null, 'старт теж під лімітом');
    }

    /** @return array<string, string> параметри адреси Google + state/nonce/code_challenge */
    private function start(?string $next): array
    {
        $this->client->request('GET', $this->base.'/account/google/start'.(null === $next ? '' : '?next='.rawurlencode($next)));

        return $this->parseStart();
    }

    /** @return array<string, string> */
    private function parseStart(): array
    {
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://google.test/o/oauth2/v2/auth?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $params);
        /** @var array<string, string> $params */
        self::assertNotEmpty($params['state']);
        self::assertNotEmpty($params['nonce']);
        self::assertNotEmpty($params['code_challenge']);

        return $params;
    }

    private function finishAtCallback(string $code, string $state): void
    {
        $this->client->request('GET', $this->base.'/account/google/callback?state='.rawurlencode($state).'&code='.rawurlencode($code));
    }

    private function assertLoginFailed(string $message, string $why = ''): void
    {
        self::assertResponseRedirects($this->base.'/account/login', null, $why);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString($message, $crawler->filter('.flash-error')->text(), $why);
        $this->client->request('GET', $this->base.'/account/');
        self::assertResponseRedirects($this->base.'/account/login', null, 'не залогінений: '.$why);
    }

    private function logout(): void
    {
        $this->client->request('GET', $this->base.'/account/');
        $this->client->submit($this->client->getCrawler()->filter('form[action$="/account/logout"]')->form());
        self::assertResponseRedirects($this->base.'/');
    }

    private function register(string $email): void
    {
        $crawler = $this->client->request('GET', $this->base.'/account/register');
        $form = $crawler->filter('form.account-form')->form(['registration[email]' => $email]);
        $consent = $form['registration[consent]'];
        self::assertInstanceOf(ChoiceFormField::class, $consent);
        $consent->tick();
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

    private function customer(string $email): Customer
    {
        $this->em()->clear();
        $customer = $this->em()->getRepository(Customer::class)->findOneBy(['email' => mb_strtolower($email)]);
        self::assertInstanceOf(Customer::class, $customer);

        return $customer;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return list<SendCustomerAuthMail> */
    private function sentMails(): array
    {
        $transport = static::getContainer()->get('messenger.transport.auth_mail');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(array_map(static fn ($e): object => $e->getMessage(), $transport->getSent()), static fn (object $m): bool => $m instanceof SendCustomerAuthMail));
    }

    private function mailUrl(string $kind): string
    {
        $mails = $this->sentMails();
        self::assertNotEmpty($mails);
        $last = $mails[\count($mails) - 1];
        self::assertSame($kind, $last->kind);

        return $last->url;
    }

    private static function email(): string
    {
        return 'google-'.bin2hex(random_bytes(4)).'@example.test';
    }
}
