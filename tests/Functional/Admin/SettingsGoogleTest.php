<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Customer\Auth\AuthAudit;
use App\Core\Customer\Entity\Customer;
use App\Core\Customer\Google\GoogleKeySource;
use App\Core\Customer\Google\GoogleSignInConfig;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use App\Core\Settings\Entity\Setting;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Crypto\SecretBox;
use App\Tests\Support\Google\FakeGoogle;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Ключі Google в адмінці (рішення власника 13.10.2026): розділ з адресами для Cloud Console та інструкцією; Client ID,
 * шифрований Client Secret і перемикач мають пріоритет над env (fallback стендів лише поки перемикач вимкнений); неповні
 * ключі при ввімкненому перемикачі — вхід вимкнений, не відкат на env; зміни — у security_audit без значень; секрет
 * не витікає в HTML; «Перевірити налаштування» — по пункту, з фейковим Google (discovery + JWKS) і без нього.
 */
final class SettingsGoogleTest extends AdminWebTestCase
{
    private const string CLIENT_ID = '123456789012-abcdefghijklmnop.apps.googleusercontent.com';
    private const string SECRET = 'GOCSPX-admin-entered-secret-9999';

    private string $site;
    private FakeGoogle $google;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        $this->site = 'https://'.$host;
        $this->client->setServerParameter('REMOTE_ADDR', '100.64.'.random_int(0, 255).'.'.random_int(1, 254));
        $google = static::getContainer()->get('google_oidc.http_client');
        self::assertInstanceOf(FakeGoogle::class, $google);
        $this->google = $google;
        $this->admin = $this->loginAs(UserRole::Admin, 'admin@example.test');
    }

    protected function tearDown(): void
    {
        $this->google->down = false;
        $this->google->expectedClientId = FakeGoogle::CLIENT_ID;
        $this->google->expectedClientSecret = FakeGoogle::CLIENT_SECRET;
        // наступним тестам — чистий env-fallback
        foreach ([SettingKeys::GOOGLE_SIGN_IN_ENABLED, SettingKeys::GOOGLE_CLIENT_ID, SettingKeys::GOOGLE_CLIENT_SECRET] as $code) {
            $this->settings()->set($code, null);
        }
        parent::tearDown();
    }

    public function testPanelShowsAddressesInstructionAndEnvFallbackState(): void
    {
        $crawler = $this->client->request('GET', $this->adminUrl('/setting'));
        self::assertResponseIsSuccessful();
        $panel = $crawler->filter('#google-sign-in');
        self::assertCount(1, $panel);
        self::assertSame('env', $panel->attr('data-google-source'), 'у .env.test ключі є, перемикач вимкнений → fallback');
        self::assertStringContainsString('стенд агенції', $panel->text());
        self::assertSame($this->site, $panel->filter('#google-origin')->attr('value'));
        self::assertSame($this->site.'/account/google/callback', $panel->filter('#google-redirect_uri')->attr('value'));
        self::assertCount(2, $panel->filter('button[data-copy]'));
        self::assertCount(7, $panel->filter('ol li'));
        self::assertStringContainsString('OAuth consent screen', $panel->filter('ol')->text());
        self::assertStringStartsWith('https://console.cloud.google.com/', (string) $panel->filter('a[target="_blank"]')->first()->attr('href'));
        self::assertSame('Перевірити налаштування', trim($panel->filter('#google-check button')->text()));
        self::assertSame($this->site.'/account/login', $panel->selectLink('Спробувати на сайті')->attr('href'));
        // три рядки групи в списку
        $list = $crawler->filter('table.datagrid')->text();
        foreach (['Вхід через Google → Client ID', 'Вхід через Google → Client Secret', 'Вхід через Google → Увімкнено'] as $label) {
            self::assertStringContainsString($label, $list);
        }
        // кнопка «Копіювати» живе в assets/admin-copy.js
        self::assertStringContainsString('assets/admin-copy.js', (string) $crawler->html());
    }

    public function testAdminKeysTakePriorityOverEnvAndIncompleteKeysDisableSignIn(): void
    {
        /** @var Logger $audit */
        $audit = static::getContainer()->get('monolog.logger.security_audit');
        $audit->pushHandler($records = new TestHandler());

        // перемикач увімкнено без ключів — вхід вимкнений, а не тихий відкат на env
        $this->edit(SettingKeys::GOOGLE_SIGN_IN_ENABLED, ['Setting[value]' => true]);
        self::assertSame(GoogleKeySource::None, $this->config()->source());
        self::assertSame('client_id_missing', $this->config()->problem());
        self::assertSame('client_id_missing', $this->client->request('GET', $this->adminUrl('/setting'))->filter('#google-sign-in')->attr('data-google-problem'));
        self::assertCount(0, $this->client->request('GET', $this->site.'/account/login')->filter('a.btn-google'), 'кнопки на сайті нема');
        $this->client->request('GET', $this->site.'/account/google/start');
        self::assertResponseStatusCodeSame(404);

        $this->edit(SettingKeys::GOOGLE_CLIENT_ID, ['Setting[value]' => self::CLIENT_ID]);
        self::assertSame('secret_missing', $this->config()->problem());
        $id = $this->settingId(SettingKeys::GOOGLE_CLIENT_SECRET);
        $crawler = $this->client->request('GET', $this->adminUrl("/setting/$id/edit"));
        self::assertSame('password', $crawler->filter('input[name="Setting[value]"]')->attr('type'));
        $this->submit($crawler, ['Setting[value]' => self::SECRET]);

        // тепер працюють ключі з адмінки: FakeGoogle приймає лише їх на token endpoint
        self::assertSame(GoogleKeySource::Settings, $this->config()->source());
        self::assertNull($this->config()->problem());
        self::assertSame(self::CLIENT_ID, $this->config()->clientId());
        self::assertSame(self::SECRET, $this->config()->clientSecret());
        self::assertSame('…9999', $this->settings()->get(SettingKeys::GOOGLE_CLIENT_SECRET), 'get() — лише маска');
        $this->google->expectedClientId = self::CLIENT_ID;
        $this->google->expectedClientSecret = self::SECRET;
        $this->logout();
        $this->client->request('GET', $this->site.'/account/google/start');
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $params);
        /** @var array<string, string> $params */
        self::assertSame(self::CLIENT_ID, $params['client_id']);
        $email = 'google-settings-'.bin2hex(random_bytes(4)).'@example.test';
        $code = $this->google->grant(['sub' => 'g-'.$email, 'email' => $email, 'name' => 'Через адмінку'], $params['nonce'], $params['code_challenge']);
        $this->client->request('GET', $this->site.'/account/google/callback?state='.rawurlencode($params['state']).'&code='.rawurlencode($code));
        self::assertResponseRedirects('/account/');
        self::assertNotNull($this->em()->getRepository(Customer::class)->findOneBy(['email' => $email]), 'покупець увійшов з ключами з адмінки');

        // HTML адмінки: ні секрету, ні конверта
        $this->client->loginUser($this->admin, 'admin');
        foreach (['/setting', "/setting/$id/edit"] as $path) {
            $html = (string) $this->client->request('GET', $this->adminUrl($path))->html();
            self::assertStringNotContainsString(self::SECRET, $html, $path);
            self::assertStringNotContainsString(SecretBox::PREFIX, $html, $path);
        }
        self::assertStringContainsString('…9999', (string) $this->client->request('GET', $this->adminUrl('/setting'))->html());

        // security_audit: changed для перемикача й Client ID (код, хто; без значення), secret_set для секрету (маска)
        $events = [];
        /** @var string $kernelSecret */
        $kernelSecret = static::getContainer()->getParameter('kernel.secret');
        foreach ($records->getRecords() as $record) {
            $json = json_encode($record->context, \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::SECRET, $record->message.$json);
            if (str_starts_with($record->message, 'settings.')) {
                $events[] = $record->message.' '.$record->context['code'];
                self::assertStringNotContainsString(self::CLIENT_ID, $json, 'значення в журнал не йдуть');
                self::assertStringNotContainsString('admin@example.test', $json);
                self::assertSame(AuthAudit::emailHmac('admin@example.test', $kernelSecret), $record->context['email_hmac']);
                self::assertNotNull($record->context['user_id']);
            }
        }
        self::assertSame([
            'settings.changed '.SettingKeys::GOOGLE_SIGN_IN_ENABLED,
            'settings.changed '.SettingKeys::GOOGLE_CLIENT_ID,
            'settings.secret_set '.SettingKeys::GOOGLE_CLIENT_SECRET,
        ], $events);

        // вимкнули перемикач — знову fallback на env (стенд), ключі з адмінки лишаються збереженими
        $this->edit(SettingKeys::GOOGLE_SIGN_IN_ENABLED, ['Setting[value]' => false]);
        self::assertSame(GoogleKeySource::Env, $this->config()->source());
        self::assertSame(FakeGoogle::CLIENT_ID, $this->config()->clientId());
        self::assertSame(self::SECRET, $this->settings()->secret(SettingKeys::GOOGLE_CLIENT_SECRET));
    }

    public function testCheckButtonReportsEachItemAndGoogleOutage(): void
    {
        $crawler = $this->client->request('GET', $this->adminUrl('/setting'));
        $crawler = $this->client->submit($crawler->filter('#google-check')->form());
        self::assertResponseRedirects();
        $result = $this->client->followRedirect()->filter('#google-check-result');
        self::assertCount(1, $result);
        $lines = $result->filter('li')->each(static fn (Crawler $li): string => trim($li->text()));
        self::assertCount(4, $lines, implode(' | ', $lines));
        self::assertStringStartsWith('✗ Client ID не задано', $lines[0]);
        self::assertStringStartsWith('✗ Client Secret не задано', $lines[1]);
        self::assertStringStartsWith('✗ Перемикач вимкнено: працюють ключі зі змінних сервера', $lines[2]);
        self::assertStringStartsWith('✓ Google відповідає: issuer https://accounts.google.com, ключів підпису 1', $lines[3]);

        // неправильний Client ID (вставили secret) + Google лежить
        $this->edit(SettingKeys::GOOGLE_CLIENT_ID, ['Setting[value]' => 'GOCSPX-this-is-a-secret-not-an-id']);
        $this->google->down = true;
        $crawler = $this->client->request('GET', $this->adminUrl('/setting'));
        $this->client->submit($crawler->filter('#google-check')->form());
        $lines = $this->client->followRedirect()->filter('#google-check-result li')->each(static fn (Crawler $li): string => trim($li->text()));
        self::assertStringStartsWith('✗ Client ID не схожий на …apps.googleusercontent.com', $lines[0]);
        self::assertStringStartsWith('✗ Google недоступний з сервера (discovery_unavailable)', $lines[3]);
        self::assertStringNotContainsString('GOCSPX-this-is-a-secret-not-an-id', $this->client->getCrawler()->filter('#google-check-result')->text(), 'значення у flash нема');

        // Google оголошує інший jwks_uri — попередження розробнику
        $this->google->down = false;
        $this->google->discoveryOverrides = ['jwks_uri' => 'https://google.test/other-certs'];
        try {
            $crawler = $this->client->request('GET', $this->adminUrl('/setting'));
            $this->client->submit($crawler->filter('#google-check')->form());
            $lines = $this->client->followRedirect()->filter('#google-check-result li')->each(static fn (Crawler $li): string => trim($li->text()));
            self::assertStringStartsWith('✗ Google оголошує інші адреси', $lines[4] ?? '');
        } finally {
            $this->google->discoveryOverrides = [];
        }

        // без CSRF — відмова; менеджеру — 403
        $this->client->request('POST', $this->adminUrl('/setting/google-check'), ['token' => 'x']);
        self::assertResponseRedirects();
        self::assertStringContainsString('CSRF', $this->client->followRedirect()->filter('.alert')->text());
        $this->loginAs(UserRole::Manager);
        $this->client->request('POST', $this->adminUrl('/setting/google-check'), ['token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    /** @param array<string, string|bool> $values */
    private function edit(string $code, array $values): void
    {
        $crawler = $this->client->request('GET', $this->adminUrl('/setting/'.$this->settingId($code).'/edit'));
        self::assertResponseIsSuccessful();
        $this->submit($crawler, $values);
    }

    /** @param array<string, string|bool> $values */
    private function submit(Crawler $crawler, array $values): void
    {
        $form = $crawler->filter('form[name="Setting"]')->form();
        foreach ($values as $name => $value) {
            if (\is_bool($value)) {
                $field = $form[$name];
                \assert($field instanceof ChoiceFormField);
                $value ? $field->tick() : $field->untick();
            } else {
                $form[$name]->setValue($value);
            }
        }
        $crawler = $this->client->submit($form);
        self::assertFormRedirected($crawler);
        $this->settings()->invalidate();
    }

    private function settingId(string $code): int
    {
        $this->settings()->ensureRows();
        $setting = $this->em()->getRepository(Setting::class)->findOneBy(['code' => $code]);
        self::assertNotNull($setting);

        return (int) $setting->getId();
    }

    private function settings(): Settings
    {
        return static::getContainer()->get(Settings::class);
    }

    private function config(): GoogleSignInConfig
    {
        return static::getContainer()->get(GoogleSignInConfig::class);
    }
}
