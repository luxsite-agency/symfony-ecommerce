<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Security\Enum\UserRole;
use App\Core\Settings\Entity\Setting;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;

/**
 * Де вводити ключ OpenRouter: кнопка в блоці AI налаштувань, «змінити» для моделі й ліміту, назва рядка — посилання
 * на редагування, банер на «Використання AI» і пункт меню «Ключ і модель AI» (лише ROLE_ADMIN).
 */
final class SettingsAiLinksTest extends AdminWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // кеш Settings переживає відкат БД між тестами: ключ, заданий в іншому тесті, не має «бути задано» тут
        $this->settings()->invalidate();
    }

    private function settings(): Settings
    {
        return static::getContainer()->get(Settings::class);
    }

    private function editPath(string $code): string
    {
        $this->settings()->ensureRows();
        $setting = $this->em()->getRepository(Setting::class)->findOneBy(['code' => $code]);
        self::assertNotNull($setting);

        return '/setting/'.$setting->getId().'/edit';
    }

    private static function assertLinksTo(string $path, ?string $href): void
    {
        self::assertNotNull($href);
        self::assertSame($path, parse_url($href, \PHP_URL_PATH));
    }

    public function testAiBlockLinksToEditOfKeyModelAndBudgetRows(): void
    {
        $this->client->disableReboot();
        $this->loginAs(UserRole::Admin, 'admin@example.test');

        $crawler = $this->client->request('GET', $this->adminUrl('/setting'));
        self::assertResponseIsSuccessful();
        $button = $crawler->filter('#ai-provider a[data-ai-edit="ai.openrouter_key"]');
        self::assertCount(1, $button);
        self::assertSame('Ввести ключ OpenRouter', trim($button->text()));
        self::assertLinksTo($this->editPath(SettingKeys::AI_OPENROUTER_KEY), $button->attr('href'));
        $get = $crawler->filter('#ai-provider a[href="https://openrouter.ai/keys"]');
        self::assertCount(1, $get);
        self::assertSame('_blank', $get->attr('target'));
        self::assertSame('noopener', $get->attr('rel'));
        self::assertLinksTo($this->editPath(SettingKeys::AI_MODEL), $crawler->filter('#ai-provider a[data-ai-edit="ai.model"]')->attr('href'));
        self::assertLinksTo($this->editPath(SettingKeys::AI_MONTHLY_BUDGET_USD), $crawler->filter('#ai-provider a[data-ai-edit="ai.monthly_budget_usd"]')->attr('href'));

        // назва рядка в списку — посилання на редагування
        $label = $crawler->filter('table.datagrid a[data-setting-edit="'.SettingKeys::AI_OPENROUTER_KEY.'"]');
        self::assertCount(1, $label);
        self::assertLinksTo($this->editPath(SettingKeys::AI_OPENROUTER_KEY), $label->attr('href'));

        // посилання відкриває форму саме цього рядка
        $this->client->request('GET', $this->adminUrl($this->editPath(SettingKeys::AI_OPENROUTER_KEY)));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="Setting[value]"][type="password"]');

        // ключ задано — «Змінити ключ», «змінити» біля моделі й ліміту
        $this->settings()->setSecret(SettingKeys::AI_OPENROUTER_KEY, 'sk-or-v1-links-test-1234');
        $crawler = $this->client->request('GET', $this->adminUrl('/setting'));
        self::assertSame('Змінити ключ', trim($crawler->filter('#ai-provider a[data-ai-edit="ai.openrouter_key"]')->text()));
        self::assertCount(1, $crawler->filter('#ai-provider [data-ai-state="ready"] a[data-ai-edit="ai.model"]'));
        self::assertCount(1, $crawler->filter('#ai-provider [data-ai-state="ready"] a[data-ai-edit="ai.monthly_budget_usd"]'));
    }

    public function testAiUsageBannerAndMenuItemForAdmin(): void
    {
        $this->client->disableReboot();
        $this->loginAs(UserRole::Admin, 'admin@example.test');
        $keyEdit = $this->editPath(SettingKeys::AI_OPENROUTER_KEY);

        $crawler = $this->client->request('GET', $this->adminUrl('/ai-usage'));
        self::assertResponseIsSuccessful();
        $banner = $crawler->filter('#ai-key-banner');
        self::assertStringContainsString('Ключ OpenRouter не задано', $banner->text());
        $button = $banner->filter('a[data-ai-edit="ai.openrouter_key"]');
        self::assertSame('Ввести ключ OpenRouter', trim($button->text()));

        // кнопка банера й пункт меню ведуть через /setting/ai-key на редагування рядка ключа
        $menu = $crawler->filter('.main-sidebar a, #main-menu a')->reduce(static fn ($a): bool => str_contains($a->text(), 'Ключ і модель AI'));
        self::assertCount(1, $menu);
        foreach ([$button->attr('href'), $menu->attr('href')] as $href) {
            self::assertNotNull($href);
            $this->client->request('GET', $href);
            self::assertResponseRedirects();
            self::assertLinksTo($keyEdit, $this->client->getResponse()->headers->get('Location'));
        }

        // ключ задано — замість банера посилання «Налаштування AI»
        $this->settings()->setSecret(SettingKeys::AI_OPENROUTER_KEY, 'sk-or-v1-links-test-1234');
        $crawler = $this->client->request('GET', $this->adminUrl('/ai-usage'));
        self::assertCount(0, $crawler->filter('.alert#ai-key-banner'));
        self::assertSame('Налаштування AI', trim($crawler->filter('#ai-key-banner a')->text()));
        self::assertLinksTo('/setting', $crawler->filter('#ai-key-banner a')->attr('href'));
    }

    public function testManagerSeesNoKeyButtonsMenuOrSettings(): void
    {
        $this->loginAs(UserRole::Manager);

        $crawler = $this->client->request('GET', $this->adminUrl('/ai-usage'));
        self::assertResponseIsSuccessful();
        $banner = $crawler->filter('#ai-key-banner');
        self::assertStringContainsString('Ключ OpenRouter не задано', $banner->text());
        self::assertStringContainsString('Зверніться до адміністратора', $banner->text());
        self::assertCount(0, $banner->filter('a'));
        self::assertStringNotContainsString('Ключ і модель AI', (string) $crawler->html());

        foreach (['/setting', '/setting/ai-key', $this->editPath(SettingKeys::AI_OPENROUTER_KEY)] as $path) {
            $this->client->request('GET', $this->adminUrl($path));
            self::assertResponseStatusCodeSame(403, $path);
        }
    }
}
