<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Ai\OpenRouterClient;
use App\Core\Customer\Auth\AuthAudit;
use App\Core\Security\Enum\UserRole;
use App\Core\Settings\Entity\Setting;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Crypto\SecretBox;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * E15 BYOK в адмінці: ключ OpenRouter зберігається шифровано, показується маскою, порожнє поле не стирає,
 * перемикач «видалити» стирає; зміна — у security_audit без значення; «Перевірити з'єднання» — flash; журнал AI менеджеру.
 */
final class SettingsAiTest extends AdminWebTestCase
{
    private const string KEY = 'sk-or-v1-functional-test-key-7777';

    private function settings(): Settings
    {
        return static::getContainer()->get(Settings::class);
    }

    private function settingId(string $code): int
    {
        $this->settings()->ensureRows();
        $setting = $this->em()->getRepository(Setting::class)->findOneBy(['code' => $code]);
        self::assertNotNull($setting);

        return (int) $setting->getId();
    }

    /** @param array<string, string|bool> $values */
    private function submitEdit(Crawler $crawler, array $values): void
    {
        $form = $crawler->filter('form[name="Setting"]')->form();
        foreach ($values as $name => $value) {
            if (\is_bool($value)) {
                $field = $form[$name];
                \assert($field instanceof \Symfony\Component\DomCrawler\Field\ChoiceFormField);
                $value ? $field->tick() : $field->untick();
            } else {
                $form[$name]->setValue($value);
            }
        }
        $crawler = $this->client->submit($form);
        self::assertFormRedirected($crawler);
    }

    public function testSecretIsSavedEncryptedShownMaskedKeptOnEmptyAndCleared(): void
    {
        $this->client->disableReboot();
        $this->loginAs(UserRole::Admin, 'admin@example.test');
        /** @var Logger $audit */
        $audit = static::getContainer()->get('monolog.logger.security_audit');
        $audit->pushHandler($records = new TestHandler());
        $id = $this->settingId(SettingKeys::AI_OPENROUTER_KEY);

        $crawler = $this->client->request('GET', $this->adminUrl("/setting/$id/edit"));
        self::assertResponseIsSuccessful();
        self::assertSame('password', $crawler->filter('input[name="Setting[value]"]')->attr('type'));
        self::assertSame('не задано', $crawler->filter('input[name="Setting[value]"]')->attr('placeholder'));
        $this->submitEdit($crawler, ['Setting[value]' => self::KEY]);

        $this->settings()->invalidate();
        self::assertSame(self::KEY, $this->settings()->secret(SettingKeys::AI_OPENROUTER_KEY));
        self::assertSame('…7777', $this->settings()->get(SettingKeys::AI_OPENROUTER_KEY));

        // список і форма: ні відкритого ключа, ні конверта
        $html = (string) $this->client->request('GET', $this->adminUrl('/setting'))->html();
        self::assertStringContainsString('…7777', $html);
        self::assertStringContainsString('задано', $html);
        self::assertStringNotContainsString(self::KEY, $html);
        self::assertStringNotContainsString(SecretBox::PREFIX, $html);
        $crawler = $this->client->request('GET', $this->adminUrl("/setting/$id/edit"));
        self::assertSame('', (string) $crawler->filter('input[name="Setting[value]"]')->attr('value', ''));
        self::assertSame('…7777', $crawler->filter('input[name="Setting[value]"]')->attr('placeholder'));
        self::assertStringNotContainsString(self::KEY, (string) $crawler->html());

        // порожнє поле — ключ лишається
        $this->submitEdit($crawler, ['Setting[value]' => '']);
        $this->settings()->invalidate();
        self::assertSame(self::KEY, $this->settings()->secret(SettingKeys::AI_OPENROUTER_KEY));

        // перемикач — ключ прибрано
        $crawler = $this->client->request('GET', $this->adminUrl("/setting/$id/edit"));
        $this->submitEdit($crawler, ['Setting[clearSecret]' => true]);
        $this->settings()->invalidate();
        self::assertNull($this->settings()->secret(SettingKeys::AI_OPENROUTER_KEY));

        // security_audit: set і cleared, без значення; email — HMAC, як в AdminLoginAudit (L3 рев'ю #70)
        self::assertTrue($records->hasInfoThatContains('settings.secret_set'));
        self::assertTrue($records->hasInfoThatContains('settings.secret_cleared'));
        /** @var string $kernelSecret */
        $kernelSecret = static::getContainer()->getParameter('kernel.secret');
        foreach ($records->getRecords() as $record) {
            $json = json_encode($record->context, \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::KEY, $record->message.$json);
            if (str_starts_with($record->message, 'settings.secret_')) {
                self::assertSame(SettingKeys::AI_OPENROUTER_KEY, $record->context['code']);
                self::assertStringNotContainsString('admin@example.test', $json);
                self::assertSame(AuthAudit::emailHmac('admin@example.test', $kernelSecret), $record->context['email_hmac']);
                self::assertNotNull($record->context['user_id']);
            }
        }
        self::assertSame('…7777', array_values(array_filter($records->getRecords(), static fn ($r): bool => 'settings.secret_set' === $r->message))[0]->context['mask']);
    }

    public function testCheckConnectionShowsKeyInfoAndWarnsWithoutKey(): void
    {
        $this->client->disableReboot();
        $this->loginAs(UserRole::Admin, 'admin@example.test');
        // мок провайдера — до першого запиту, бо сторінка налаштувань ініціалізує шлюз і клієнт
        static::getContainer()->set(OpenRouterClient::class, new OpenRouterClient(
            new MockHttpClient([new MockResponse(json_encode(['data' => ['label' => 'shop-key', 'limit' => 50, 'usage' => 2.25, 'limit_remaining' => 47.75, 'is_free_tier' => false]], \JSON_THROW_ON_ERROR))]),
            $this->settings(),
            'https://openrouter.test/api/v1/',
        ));

        $crawler = $this->client->request('GET', $this->adminUrl('/setting'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ключ OpenRouter не задано', $crawler->filter('#ai-provider')->text());
        $this->client->submit($crawler->filter('#ai-provider form')->form());
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Ключ OpenRouter не задано', $crawler->filter('.alert')->text());

        $this->settings()->setSecret(SettingKeys::AI_OPENROUTER_KEY, self::KEY);
        $crawler = $this->client->request('GET', $this->adminUrl('/setting'));
        self::assertStringContainsString('Модель:', $crawler->filter('#ai-provider')->text());
        $this->client->submit($crawler->filter('#ai-provider form')->form());
        self::assertResponseRedirects();
        $text = $this->client->followRedirect()->filter('.alert')->text();
        self::assertStringContainsString('ключ «shop-key», витрачено $2.25, ліміт $50.00, лишилось $47.75', $text);
        self::assertStringNotContainsString(self::KEY, $text);
        self::assertSame('check', $this->em()->getConnection()->fetchOne('SELECT feature FROM ai_usage ORDER BY id DESC LIMIT 1'));

        // CSRF
        $this->client->request('POST', $this->adminUrl('/setting/ai-check'), ['token' => 'bad']);
        self::assertResponseRedirects();
        self::assertStringContainsString('CSRF', $this->client->followRedirect()->filter('.alert')->text());
    }

    /** M1 рев'ю #70: конверт під іншим SETTINGS_SECRET_KEY — банер «введіть знову», бейдж, flash; не 500. */
    public function testUnreadableSecretShowsReenterWarningInsteadOf500(): void
    {
        $this->client->disableReboot();
        $this->loginAs(UserRole::Admin, 'admin@example.test');
        $foreign = (new SecretBox(SecretBox::generateKey()))->encrypt(self::KEY);
        $this->settings()->set(SettingKeys::AI_OPENROUTER_KEY, $foreign);
        $id = $this->settingId(SettingKeys::AI_OPENROUTER_KEY);

        $crawler = $this->client->request('GET', $this->adminUrl('/setting'));
        self::assertResponseIsSuccessful();
        $banner = $crawler->filter('#ai-provider [data-ai-state="unreadable"]');
        self::assertCount(1, $banner);
        self::assertStringContainsString('треба ввести знову', $banner->text());
        self::assertStringContainsString('не розшифрувати — введіть знову', $crawler->filter('table.datagrid')->text());
        self::assertStringNotContainsString('>задано</span>', (string) $crawler->filter('table.datagrid')->html(), 'бейдж «задано» (Ready) — не «не задано»');
        self::assertCount(1, $crawler->filter('#ai-provider button[disabled]'), 'кнопка перевірки неактивна');
        self::assertStringNotContainsString(self::KEY, (string) $crawler->html());

        $this->client->request('POST', $this->adminUrl('/setting/ai-check'), ['token' => $this->csrf()]);
        self::assertResponseRedirects();
        $alert = $this->client->followRedirect()->filter('.alert')->text();
        self::assertStringContainsString('введіть ключ OpenRouter знову', $alert);
        self::assertSame(0, $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM ai_usage WHERE status = 'queued'"));

        // форма редагування: підказка «введіть знову», після введення — задано
        $crawler = $this->client->request('GET', $this->adminUrl("/setting/$id/edit"));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Введіть значення знову', $crawler->filter('form[name="Setting"]')->text());
        $this->submitEdit($crawler, ['Setting[value]' => self::KEY]);
        $this->settings()->invalidate();
        self::assertSame(self::KEY, $this->settings()->secret(SettingKeys::AI_OPENROUTER_KEY));
        self::assertCount(1, $this->client->request('GET', $this->adminUrl('/setting'))->filter('#ai-provider [data-ai-state="ready"]'));
    }

    /** L1 рев'ю #70: flash EasyAdmin — HTML (|raw), текст від провайдера екранується. */
    public function testProviderErrorTextIsEscapedInFlash(): void
    {
        $this->client->disableReboot();
        $this->loginAs(UserRole::Admin, 'admin@example.test');
        static::getContainer()->set(OpenRouterClient::class, new OpenRouterClient(
            new MockHttpClient([new MockResponse(json_encode(['error' => ['message' => '<b>bad</b> key <script>x()</script>']], \JSON_THROW_ON_ERROR), ['http_code' => 400])]),
            $this->settings(),
            'https://openrouter.test/api/v1/',
        ));
        $this->settings()->setSecret(SettingKeys::AI_OPENROUTER_KEY, self::KEY);
        $this->client->request('GET', $this->adminUrl('/setting'));
        $this->client->request('POST', $this->adminUrl('/setting/ai-check'), ['token' => $this->csrf()]);
        self::assertResponseRedirects();
        $alert = $this->client->followRedirect()->filter('.alert');
        self::assertCount(0, $alert->filter('b, script'), 'теги провайдера не стали HTML');
        self::assertStringContainsString('<b>bad</b> key', $alert->text());
        self::assertStringContainsString('OpenRouter 400', $alert->text());
    }

    private function csrf(): string
    {
        return (string) $this->client->getCrawler()->filter('#ai-provider input[name="token"]')->attr('value');
    }

    public function testManagerSeesAiUsageJournalButNotSettings(): void
    {
        $this->loginAs(UserRole::Manager);
        $this->client->request('GET', $this->adminUrl('/ai-usage'));
        self::assertResponseIsSuccessful();
        $this->client->request('POST', $this->adminUrl('/setting/ai-check'), ['token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }
}
