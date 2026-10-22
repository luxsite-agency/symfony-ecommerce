<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Audit\Entity\AdminAuditEntry;
use App\Core\Security\Enum\UserRole;
use App\Core\Settings\Entity\Setting;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

/**
 * «Рядок платформи у футері» в «Налаштуваннях сайту» (власник 19.10.2026): лише ROLE_ADMIN; у списку — підпис і «типове»,
 * у формі — три перемикачі з чинним «Коротко» і підказкою про Apache 2.0; збереження змінює футер сайту і пишеться
 * в журнал змін (admin_audit_entry) і security_audit (settings.changed без значення).
 */
final class SettingsPlatformCreditTest extends AdminWebTestCase
{
    protected function tearDown(): void
    {
        static::getContainer()->get(Settings::class)->set(SettingKeys::PLATFORM_CREDIT, null);
        parent::tearDown();
    }

    public function testAdminSwitchesModeWithAuditAndSiteFollows(): void
    {
        $this->client->disableReboot();
        $this->loginAs(UserRole::Admin, 'admin@example.test');
        /** @var Logger $logger */
        $logger = static::getContainer()->get('monolog.logger.security_audit');
        $logger->pushHandler($records = new TestHandler());

        $crawler = $this->client->request('GET', $this->adminUrl('/setting'));
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('tr[data-id="'.$this->settingId().'"]');
        self::assertStringContainsString('Сайт → Рядок платформи у футері', $row->text());
        self::assertStringContainsString('Коротко', $row->text());
        self::assertStringContainsString('типове', $row->text());

        $crawler = $this->client->request('GET', $this->adminUrl('/setting/'.$this->settingId().'/edit'));
        self::assertResponseIsSuccessful();
        $radios = $crawler->filter('input[type="radio"][name="Setting[value]"]');
        self::assertSame(['full', 'short', 'none'], $radios->each(static fn ($r): string => (string) $r->attr('value')));
        self::assertSame('short', $crawler->filter('input[name="Setting[value]"]:checked')->attr('value'), 'вибрано чинне типове');
        foreach (['Повний', 'Коротко', 'Не показувати'] as $label) {
            self::assertStringContainsString($label, $crawler->filter('form[name="Setting"]')->text());
        }
        self::assertStringContainsString('вимога NOTICE стосується лише поширення коду', $crawler->filter('form[name="Setting"]')->text());

        $form = $crawler->filter('form[name="Setting"]')->form();
        $form['Setting[value]']->setValue('none');
        self::assertFormRedirected($this->client->submit($form));

        $setting = $this->em()->getRepository(Setting::class)->findOneBy(['code' => SettingKeys::PLATFORM_CREDIT]);
        self::assertSame('none', $setting?->getValue());
        /** @var string $host */
        $host = static::getContainer()->getParameter('site_host');
        self::assertCount(0, $this->client->request('GET', 'https://'.$host.'/')->filter('.site-footer .footer-powered'), 'на сайті рядка нема');

        $entry = $this->em()->getRepository(AdminAuditEntry::class)->findOneBy(['entityType' => 'setting', 'entityId' => $this->settingId(), 'field' => 'value'], ['id' => 'DESC']);
        self::assertNotNull($entry, 'зміна в журналі змін адмінки');
        self::assertSame('none', $entry->getAfter());
        $events = array_values(array_filter(array_map(static fn ($r): string => $r->message.' '.($r->context['code'] ?? ''), $records->getRecords()), static fn (string $e): bool => str_starts_with($e, 'settings.')));
        self::assertSame(['settings.changed '.SettingKeys::PLATFORM_CREDIT], $events);

        $crawler = $this->client->request('GET', $this->adminUrl('/setting'));
        self::assertStringContainsString('Не показувати', $crawler->filter('tr[data-id="'.$this->settingId().'"]')->text());
    }

    public function testManagerHasNoAccess(): void
    {
        $this->loginAs(UserRole::Manager, 'olena@example.test');
        $this->client->request('GET', $this->adminUrl('/setting/'.$this->settingId().'/edit'));
        self::assertResponseStatusCodeSame(403);
    }

    private function settingId(): int
    {
        static::getContainer()->get(Settings::class)->ensureRows();
        $setting = $this->em()->getRepository(Setting::class)->findOneBy(['code' => SettingKeys::PLATFORM_CREDIT]);
        self::assertNotNull($setting);

        return (int) $setting->getId();
    }
}
