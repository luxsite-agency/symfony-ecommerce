<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site\Account;

use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Message\SendCustomerAuthMail;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Кабінет покупця, E12 PR-1: реєстрація з підтвердженням email, вхід паролем і одноразовим посиланням, відновлення
 * пароля, вихід; однакові відповіді для наявних і відсутніх адрес; ліміти; адмінські облікові записи на сайті не входять.
 * Листи — у транспорті auth_mail (in-memory): читати до наступного запиту (запит перезавантажує ядро).
 */
final class CustomerAuthTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $base;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->base = 'https://'.$host;
        // свій IP на тест: ліміти (кеш у файлах) не накопичуються між тестами; Origin — stateless CSRF форм
        $this->client->setServerParameter('REMOTE_ADDR', '203.0.113.'.random_int(1, 254));
        $this->client->setServerParameter('HTTP_ORIGIN', $this->base);
    }

    public function testRegisterVerifyLoginLogout(): void
    {
        $email = self::email();
        $this->register($email);
        self::assertResponseRedirects($this->base.'/account/login');
        self::assertSame('', $this->sentMails()[0]->name ?? null, 'у листі підтвердження нема імені (рев\'ю #45, п. 7)');
        $url = $this->mailUrl(SendCustomerAuthMail::VERIFY);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Перевірте пошту', $crawler->filter('.flash-success')->text());

        // до підтвердження входу нема, і відповідь та сама, що для відсутньої адреси (пароля ще нема)
        $this->login($email, 'correct horse battery');
        $unverifiedError = $this->client->followRedirect()->filter('.flash-error')->text();
        self::assertStringNotContainsString('Email ще не підтверджено', $unverifiedError);
        $this->login(self::email(), 'correct horse battery');
        self::assertSame($unverifiedError, $this->client->followRedirect()->filter('.flash-error')->text(), 'неперевірена й відсутня адреси не розрізняються');

        // GET з листа токен не витрачає (сканери пошти), POST з імʼям і паролем підтверджує й впускає
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('strict-origin', $this->client->getResponse()->headers->get('Referrer-Policy'));
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful('другий GET — так само дійсний');
        $this->client->submit($crawler->filter('form.account-form')->form(['verify_email[name]' => 'Олена', 'verify_email[password]' => 'short']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('щонайменше 10', $this->client->getCrawler()->filter('.form-error')->text());
        $this->client->submit($this->client->getCrawler()->filter('form.account-form')->form(['verify_email[name]' => 'Олена', 'verify_email[password]' => 'correct horse battery']));
        self::assertResponseRedirects('/account/');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Олена', $crawler->filter('main, body')->text());
        self::assertStringContainsString('noindex', (string) $this->client->getResponse()->headers->get('X-Robots-Tag'));

        $this->client->request('POST', $url, ['verify_email' => ['name' => 'Інша', 'password' => 'another password', '_token' => 'csrf-token']]);
        self::assertResponseStatusCodeSame(410, 'посилання одноразове');

        // вихід — POST з CSRF; після нього кабінет веде на вхід
        $this->client->request('GET', $this->base.'/account/');
        $this->client->submit($this->client->getCrawler()->filter('form[action$="/account/logout"]')->form());
        self::assertResponseRedirects($this->base.'/');
        $this->client->request('GET', $this->base.'/account/');
        self::assertResponseRedirects($this->base.'/account/login');

        // знову паролем, email у іншому регістрі
        $this->login(strtoupper($email), 'correct horse battery');
        self::assertResponseRedirects($this->base.'/account/');
        $customer = $this->customer($email);
        self::assertNotNull($customer->getLastLoginAt());
        self::assertSame('uk', $customer->getLocale());
    }

    public function testRegisteringTakenAddressSendsNoticeAndKeepsPassword(): void
    {
        $email = self::email();
        $this->createCustomer($email, 'old password 123');
        $this->register($email);
        self::assertResponseRedirects($this->base.'/account/login', null, 'відповідь та сама, що для нової адреси');
        self::assertSame('', $this->sentMails()[0]->name ?? null, 'лист «акаунт уже є» без імені');
        self::assertStringContainsString('/account/password/', $this->mailUrl(SendCustomerAuthMail::ACCOUNT_EXISTS));

        $this->login($email, 'old password 123');
        self::assertResponseRedirects($this->base.'/account/');
        self::assertSame('Покупець', $this->customer($email)->getName(), 'ім\'я не перезаписано');
    }

    /**
     * Рев'ю безпеки #45, п. 2: «зловмисник» реєструє чужу адресу першим, власник отримує лист і відкриває його. Пароля
     * у формі реєстрації нема (підсунуте поле робить форму недійсною), імʼя й пароль задає той, хто відкрив лист.
     */
    public function testPreRegistrationGivesAttackerNothing(): void
    {
        $email = self::email();
        $crawler = $this->client->request('GET', $this->base.'/account/register');
        self::assertCount(0, $crawler->filter('input[type=password]'), 'реєстрація без пароля');
        $this->client->request('POST', $this->base.'/account/register', ['registration' => [
            'email' => $email,
            'password' => 'attacker password',
            '_token' => 'csrf-token',
        ]]);
        self::assertResponseStatusCodeSame(422, 'зайве поле пароля');
        self::assertSame([], $this->sentMails());

        $this->register($email); // «зловмисник»
        $link = $this->mailUrl(SendCustomerAuthMail::VERIFY); // лист іде у скриньку власника
        $this->client->restart();
        $this->client->setServerParameter('HTTP_ORIGIN', $this->base);
        $this->verify($link, 'Власник', 'owner password 1');
        self::assertResponseRedirects('/account/');
        self::assertSame('Власник', $this->customer($email)->getName());

        $this->client->restart();
        $this->client->setServerParameter('HTTP_ORIGIN', $this->base);
        $this->login($email, 'attacker password');
        self::assertResponseRedirects($this->base.'/account/login');
        $this->login($email, 'owner password 1');
        self::assertResponseRedirects($this->base.'/account/');
    }

    /**
     * Обидва порядки кліків (рев'ю #45, п. 2): власник зареєструвався, «зловмисник» повторив реєстрацію з іншого IP,
     * у скриньці два листи. Хоч би який власник відкрив, пароль його, а другий лист після цього не діє.
     */
    public function testRepeatedRegistrationEitherLinkGivesOwnerTheAccount(): void
    {
        foreach (['перший лист' => 0, 'другий лист' => 1] as $case => $opened) {
            $email = self::email();
            $this->client->setServerParameter('REMOTE_ADDR', '203.0.113.'.random_int(1, 254));
            $this->register($email); // власник
            $links = [$this->mailUrl(SendCustomerAuthMail::VERIFY)];
            $this->client->restart();
            $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.'.random_int(1, 254));
            $this->client->setServerParameter('HTTP_ORIGIN', $this->base);
            $this->register($email); // «зловмисник»
            $links[] = $this->mailUrl(SendCustomerAuthMail::VERIFY);
            self::assertNotSame($links[0], $links[1]);

            $this->client->restart();
            $this->client->setServerParameter('HTTP_ORIGIN', $this->base);
            $this->verify($links[$opened], 'Власник', 'owner password 1');
            self::assertResponseRedirects('/account/', null, $case);
            $this->client->request('GET', $links[1 - $opened]);
            self::assertResponseStatusCodeSame(410, $case.': інший лист згас');

            $this->client->restart();
            $this->client->setServerParameter('HTTP_ORIGIN', $this->base);
            $this->login($email, 'owner password 1');
            self::assertResponseRedirects($this->base.'/account/', null, $case);
            $this->client->restart();
            $this->client->setServerParameter('HTTP_ORIGIN', $this->base);
        }
    }

    public function testPasswordResetLogsOutOtherDevices(): void
    {
        $email = self::email();
        $this->createCustomer($email, 'old password 123');
        // «інший пристрій»: вхід з remember_me
        $this->login($email, 'old password 123', remember: true);
        self::assertResponseRedirects($this->base.'/account/');
        $remember = $this->client->getCookieJar()->get('SHOP_REMEMBER', '/', parse_url($this->base, \PHP_URL_HOST));
        self::assertNotNull($remember);

        // «другий пристрій» — той самий клієнт з порожніми cookie; cookie першого збережено
        $firstDevice = $this->client->getCookieJar()->all();
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $this->base.'/account/password');
        $this->client->submit($this->client->getCrawler()->filter('form.account-form')->form(['email_request[email]' => $email]));
        self::assertResponseRedirects($this->base.'/account/login');
        $url = $this->mailUrl(SendCustomerAuthMail::RESET);

        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form.account-form')->form(['new_password[password]' => 'short']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('щонайменше 10', $this->client->getCrawler()->filter('.form-error')->text());
        $this->client->submit($this->client->getCrawler()->filter('form.account-form')->form(['new_password[password]' => 'brand new password']));
        self::assertResponseRedirects('/account/');
        self::assertSame(SendCustomerAuthMail::PASSWORD_CHANGED, $this->sentMails()[0]->kind ?? null);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(410, 'посилання одноразове');

        $this->client->getCookieJar()->clear();
        foreach ($firstDevice as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
        // перший пристрій: сесія й remember_me більше не діють
        $this->client->request('GET', $this->base.'/account/');
        self::assertResponseRedirects($this->base.'/account/login');
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($remember);
        $this->client->request('GET', $this->base.'/account/');
        self::assertResponseRedirects($this->base.'/account/login', null, 'remember_me з підписом старого пароля');

        $this->login($email, 'old password 123');
        self::assertResponseRedirects($this->base.'/account/login');
        $this->login($email, 'brand new password');
        self::assertResponseRedirects($this->base.'/account/');
    }

    public function testRememberMeKeepsCustomerLoggedInAfterSessionLoss(): void
    {
        $email = self::email();
        $this->createCustomer($email, 'remember password');
        $this->login($email, 'remember password', remember: true);
        $remember = $this->client->getCookieJar()->get('SHOP_REMEMBER', '/', parse_url($this->base, \PHP_URL_HOST));
        self::assertNotNull($remember);
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($remember);
        $this->client->request('GET', $this->base.'/account/');
        self::assertResponseIsSuccessful();
    }

    public function testLoginLinkIsOneTimeAndNeedsPost(): void
    {
        $email = self::email();
        $this->createCustomer($email, 'some password 1');
        $crawler = $this->client->request('GET', $this->base.'/account/login-link');
        $this->client->submit($crawler->filter('form.account-form')->form(['email_request[email]' => $email]));
        self::assertResponseRedirects($this->base.'/account/login');
        $url = $this->mailUrl(SendCustomerAuthMail::LOGIN_LINK);
        self::assertStringContainsString('/account/login-link/check?', $url);

        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful('GET лише показує кнопку');
        $this->client->request('GET', $this->base.'/account/');
        self::assertResponseRedirects($this->base.'/account/login', null, 'GET не впустив');

        $crawler = $this->client->request('GET', $url);
        $this->client->submit($crawler->filter('form.account-form')->form());
        self::assertResponseRedirects($this->base.'/account/');

        $this->client->restart();
        $this->client->setServerParameter('HTTP_ORIGIN', $this->base);
        $this->client->request('POST', $url, ['_token' => 'csrf-token']);
        self::assertResponseRedirects($this->base.'/account/login', null, 'вдруге посилання не діє');
        $this->client->request('GET', $this->base.'/account/');
        self::assertResponseRedirects($this->base.'/account/login');
    }

    /**
     * Рев'ю безпеки #45, п. 3 (login CSRF): чужа сторінка шле POST на посилання зловмисника з його власного листа. З
     * чужим Origin і без токена вхід не стається, а посилання не витрачається.
     */
    public function testLoginLinkNeedsCsrfAndForeignPostDoesNotConsumeIt(): void
    {
        $email = self::email();
        $this->createCustomer($email, 'some password 1');
        $crawler = $this->client->request('GET', $this->base.'/account/login-link');
        $this->client->submit($crawler->filter('form.account-form')->form(['email_request[email]' => $email]));
        $url = $this->mailUrl(SendCustomerAuthMail::LOGIN_LINK);

        $this->client->restart();
        $foreign = ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/auto'];
        $this->client->request('POST', $url, ['_token' => 'csrf-token'], server: $foreign);
        self::assertResponseRedirects($this->base.'/account/login', null, 'чужий Origin');
        $this->client->request('POST', $url, server: $foreign);
        self::assertResponseRedirects($this->base.'/account/login', null, 'без токена');
        $this->client->request('GET', $this->base.'/account/');
        self::assertResponseRedirects($this->base.'/account/login', null, 'не увійшли');

        // посилання ціле: власник листа входить кнопкою на своїй сторінці
        $this->client->setServerParameter('HTTP_ORIGIN', $this->base);
        $crawler = $this->client->request('GET', $url);
        self::assertCount(1, $crawler->filter('form.account-form input[name=_token]'));
        $this->client->submit($crawler->filter('form.account-form')->form());
        self::assertResponseRedirects($this->base.'/account/');
    }

    /** Рев'ю безпеки #45, п. 4а: «вимкнено» бачить лише той, хто знає пароль; з чужим паролем як для відсутньої адреси. */
    public function testDisabledAccountIsNotRevealedWithoutPassword(): void
    {
        $email = self::email();
        $this->createCustomer($email, 'right password 1');
        $this->customer($email)->setActive(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->login(self::email(), 'wrong password 1');
        $unknown = $this->client->followRedirect()->filter('.flash-error')->text();
        $this->login($email, 'wrong password 1');
        self::assertSame($unknown, $this->client->followRedirect()->filter('.flash-error')->text(), 'вимкнений з чужим паролем = відсутня адреса');
        $this->login($email, 'right password 1');
        self::assertStringContainsString('Акаунт вимкнено', $this->client->followRedirect()->filter('.flash-error')->text());
    }

    /** Рев'ю безпеки #45, п. 10: вхід змінює ID сесії (session fixation). */
    public function testLoginChangesSessionId(): void
    {
        $email = self::email();
        $this->createCustomer($email, 'right password 1');
        $this->login($email, 'wrong password 1'); // невдача кладе помилку в сесію, тож сесія вже є
        $before = $this->client->getCookieJar()->get('MOCKSESSID')?->getValue();
        self::assertNotNull($before);
        $this->login($email, 'right password 1');
        self::assertResponseRedirects($this->base.'/account/');
        $after = $this->client->getCookieJar()->get('MOCKSESSID')?->getValue();
        self::assertNotNull($after);
        self::assertNotSame($before, $after);
    }

    /**
     * Рев'ю безпеки #45, п. 1: сторінки кабінету (зокрема з токеном листа в адресі) без GTM, GA4, Meta Pixel і «Коду в
     * head/body»; ті самі налаштування на головній дають теги.
     */
    public function testAccountPagesRenderNoAnalyticsOrCustomCode(): void
    {
        $settings = static::getContainer()->get(Settings::class);
        $settings->set(SettingKeys::TAGS_ON_DEV, '1');
        $settings->set(SettingKeys::GTM_CONTAINER_ID, 'GTM-ACC45');
        $settings->set(SettingKeys::FB_PIXEL_ID, '450450450450');
        $settings->set(SettingKeys::CONSENT_GRANTED_LOCALES, 'uk,ru');
        $settings->set(SettingKeys::HTML_HEAD, '<script data-custom="acc-head">1</script>');
        $settings->set(SettingKeys::HTML_BODY_START, '<div data-custom="acc-start"></div>');
        $settings->set(SettingKeys::HTML_BODY_END, '<script data-custom="acc-end">2</script>');
        $markers = ['GTM-ACC45', '450450450450', 'fbq(', 'googletagmanager', 'data-custom="acc-', 'data-analytics-events'];

        $home = (string) $this->client->request('GET', $this->base.'/')->html();
        self::assertStringContainsString('GTM-ACC45', $home, 'контроль: на сайті теги є');
        self::assertStringContainsString("fbq('init','450450450450')", $home);
        self::assertStringContainsString('data-custom="acc-head"', $home);

        $this->register(self::email());
        $verify = $this->mailUrl(SendCustomerAuthMail::VERIFY);
        $known = self::email();
        $this->createCustomer($known, 'some password 1');
        $crawler = $this->client->request('GET', $this->base.'/account/password');
        $this->client->submit($crawler->filter('form.account-form')->form(['email_request[email]' => $known]));
        $reset = $this->mailUrl(SendCustomerAuthMail::RESET);
        $crawler = $this->client->request('GET', $this->base.'/account/login-link');
        $this->client->submit($crawler->filter('form.account-form')->form(['email_request[email]' => $known]));
        $link = $this->mailUrl(SendCustomerAuthMail::LOGIN_LINK);

        foreach ([$verify, $reset, $link, $this->base.'/account/login', $this->base.'/account/register', $this->base.'/ru/account/login'] as $url) {
            $html = (string) $this->client->request('GET', $url)->html();
            self::assertResponseIsSuccessful($url);
            // повторне рев'ю #45, п. A: Referer з цих сторінок лише origin, токен не йде в document.referrer наступної
            self::assertSame('strict-origin', $this->client->getResponse()->headers->get('Referrer-Policy'), $url);
            self::assertStringContainsString('<meta name="referrer" content="strict-origin">', $html, $url.': meta referrer (проксі може перезаписати заголовок)');
            foreach ($markers as $marker) {
                self::assertStringNotContainsString($marker, $html, $url.': '.$marker);
            }
        }

        // повторне рев'ю #45, п. C: обрізаний токен дає 404, сторінку помилки рендерить підзапит без атрибутів маршруту
        foreach ([substr($verify, 0, -1), substr($reset, 0, -5), $this->base.'/ru/account/verify/Short_Token'] as $url) {
            $html = (string) $this->client->request('GET', $url)->html();
            self::assertResponseStatusCodeSame(404, $url);
            self::assertSame('strict-origin', $this->client->getResponse()->headers->get('Referrer-Policy'), $url);
            // meta referrer на 404 перевіряти тут не можна: у test/debug 404 рендерить сторінка винятку Symfony, не наш шаблон
            foreach ($markers as $marker) {
                self::assertStringNotContainsString($marker, $html, $url.' (404): '.$marker);
            }
        }
        $settings->invalidate();
    }

    /**
     * Повторне рев'ю безпеки #45, п. A: журнал 404 не зберігає ні шляхів кабінету (обрізаний токен), ні referer з
     * токеном: перехід зі сторінки кабінету записується лише як origin сайту.
     */
    public function testNotFoundLogKeepsNoAccountTokens(): void
    {
        $known = self::email();
        $this->createCustomer($known, 'some password 1');
        $crawler = $this->client->request('GET', $this->base.'/account/password');
        $this->client->submit($crawler->filter('form.account-form')->form(['email_request[email]' => $known]));
        $reset = $this->mailUrl(SendCustomerAuthMail::RESET);
        $token = substr($reset, strrpos($reset, '/') + 1);
        $missing = '/no-such-page-'.bin2hex(random_bytes(3));

        $this->client->request('GET', $this->base.$missing, server: ['HTTP_REFERER' => $reset]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', substr($reset, 0, -1));
        self::assertResponseStatusCodeSame(404);

        $db = static::getContainer()->get(Connection::class);
        self::assertSame($this->base.'/', $db->fetchOne('SELECT referer FROM seo_not_found WHERE path = ?', [$missing]), 'referer лише origin');
        self::assertFalse($db->fetchOne('SELECT 1 FROM seo_not_found WHERE path LIKE ? OR referer LIKE ?', ['%/account/%', '%'.substr($token, 0, 20).'%']), 'кабінет і токен у журналі відсутні');
    }

    /**
     * Повторне рев'ю безпеки #45, п. D: Security::login() перевіряє лише checkPreAuth, тож вимкнений покупець не має
     * ні нового листа з посиланням, ні входу через сторінку нового пароля; відповідь та сама, що й для активного.
     */
    public function testDisabledCustomerGetsNoResetAndNoLoginFromMailPages(): void
    {
        $email = self::email();
        $this->createCustomer($email, 'right password 1');
        $crawler = $this->client->request('GET', $this->base.'/account/password');
        $this->client->submit($crawler->filter('form.account-form')->form(['email_request[email]' => $email]));
        $reset = $this->mailUrl(SendCustomerAuthMail::RESET);
        $resetPage = $this->client->request('GET', $reset);
        $this->customer($email)->setActive(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        // реєстрація на вимкнену адресу: та сама відповідь, без листа «акаунт уже є» з посиланням на новий пароль
        $this->register($email);
        self::assertResponseRedirects($this->base.'/account/login');
        self::assertSame([], $this->sentMails());

        // відкрите раніше посилання на новий пароль не впускає вимкненого
        $this->client->submit($resetPage->filter('form.account-form')->form(['new_password[password]' => 'brand new password']));
        self::assertResponseStatusCodeSame(410);
        $this->client->request('GET', $this->base.'/account/');
        self::assertResponseRedirects($this->base.'/account/login');
    }

    public function testUnknownAndUnverifiedAddressesGetTheSameAnswerWithoutMail(): void
    {
        $unverified = self::email();
        $this->register($unverified);
        $this->mailUrl(SendCustomerAuthMail::VERIFY);
        foreach ([self::email(), $unverified] as $email) {
            foreach (['/account/password', '/account/login-link'] as $path) {
                $crawler = $this->client->request('GET', $this->base.$path);
                $this->client->submit($crawler->filter('form.account-form')->form(['email_request[email]' => $email]));
                self::assertResponseRedirects($this->base.'/account/login', null, $path);
                self::assertSame([], $this->sentMails(), $path.': листа нема');
            }
        }
    }

    public function testMailsToOneAddressAreRateLimited(): void
    {
        $email = self::email();
        $this->createCustomer($email, 'some password 1');
        $sent = 0;
        for ($i = 0; $i < 5; ++$i) {
            $crawler = $this->client->request('GET', $this->base.'/account/password');
            $this->client->submit($crawler->filter('form.account-form')->form(['email_request[email]' => $email]));
            self::assertResponseRedirects($this->base.'/account/login');
            $sent += \count($this->sentMails());
        }
        self::assertSame(3, $sent, 'не більше 3 листів на адресу за 15 хв');
    }

    public function testLoginIsThrottled(): void
    {
        $email = self::email();
        $this->createCustomer($email, 'right password 1');
        for ($i = 0; $i < 5; ++$i) {
            $this->login($email, 'wrong password');
        }
        $this->login($email, 'right password 1');
        self::assertResponseRedirects($this->base.'/account/login', null, 'шоста спроба — навіть з правильним паролем');
    }

    public function testAdminAccountsAndForeignOriginCannotLogIn(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new User('admin-'.bin2hex(random_bytes(3)).'@example.test', 'Admin', UserRole::Admin);
        $admin->setPasswordHash($hasher->hashPassword($admin, 'admin password 1'));
        $em->persist($admin);
        $em->flush();
        $this->login($admin->getEmail(), 'admin password 1');
        self::assertResponseRedirects($this->base.'/account/login', null, 'адмінські облікові записи на сайті не входять');

        $email = self::email();
        $this->createCustomer($email, 'right password 1');
        // форма з чужого сайту: Origin і Referer — чужі (KernelBrowser інакше підставив би Referer з історії)
        $this->client->request('POST', $this->base.'/account/login', ['email' => $email, 'password' => 'right password 1', '_csrf_token' => 'csrf-token'], server: [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_REFERER' => 'https://evil.example/form',
        ]);
        self::assertResponseRedirects($this->base.'/account/login', null, 'CSRF входу: чужий Origin');
        $this->client->request('POST', $this->base.'/account/login', ['email' => $email, 'password' => 'right password 1', '_csrf_token' => 'csrf-token']);
        self::assertResponseRedirects($this->base.'/account/', null, 'свій Origin — вхід');
    }

    public function testDisabledCustomerIsLoggedOutOnNextRequest(): void
    {
        $email = self::email();
        $this->createCustomer($email, 'right password 1');
        $this->login($email, 'right password 1');
        $this->client->request('GET', $this->base.'/account/');
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->customer($email)->setActive(false);
        $em->flush();
        $this->client->request('GET', $this->base.'/account/');
        self::assertResponseRedirects($this->base.'/account/login');
    }

    public function testLocalizedPagesAndAccessRules(): void
    {
        $this->client->request('GET', $this->base.'/ru/account/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Вход');
        $this->client->request('GET', $this->base.'/ru/account/');
        self::assertResponseRedirects($this->base.'/ru/account/login');
        foreach (['/account/register', '/account/password', '/account/login-link'] as $path) {
            $this->client->request('GET', $this->base.$path);
            self::assertResponseIsSuccessful($path);
            self::assertSame([], $this->client->getResponse()->headers->getCookies(), $path.': сторінка входу без сесії');
        }
    }

    private function register(string $email): void
    {
        $crawler = $this->client->request('GET', $this->base.'/account/register');
        $form = $crawler->filter('form.account-form')->form(['registration[email]' => $email]);
        $consent = $form['registration[consent]'];
        self::assertInstanceOf(ChoiceFormField::class, $consent);
        $consent->tick(); // згода на обробку ПД обов'язкова (E12 PR-6)
        $this->client->submit($form);
    }

    /** Сторінка з листа підтвердження: імʼя й пароль задає той, хто відкрив лист. */
    private function verify(string $url, string $name, string $password): void
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form.account-form')->form(['verify_email[name]' => $name, 'verify_email[password]' => $password]));
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
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $customer = new Customer($email, 'Покупець', 'uk');
        $customer->verifyEmail(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, $password));
        $em->persist($customer);
        $em->flush();

        return $customer;
    }

    private function customer(string $email): Customer
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $customer = $em->getRepository(Customer::class)->findOneBy(['email' => mb_strtolower($email)]);
        self::assertInstanceOf(Customer::class, $customer);

        return $customer;
    }

    /** @return list<SendCustomerAuthMail> листи останнього запиту */
    private function sentMails(): array
    {
        $transport = static::getContainer()->get('messenger.transport.auth_mail');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(array_map(static fn ($e): object => $e->getMessage(), $transport->getSent()), static fn (object $m): bool => $m instanceof SendCustomerAuthMail));
    }

    private function mailUrl(string $kind): string
    {
        $mails = $this->sentMails();
        self::assertCount(1, $mails);
        self::assertSame($kind, $mails[0]->kind);

        return $mails[0]->url;
    }

    private static function email(): string
    {
        return 'buyer-'.bin2hex(random_bytes(4)).'@example.test';
    }
}
