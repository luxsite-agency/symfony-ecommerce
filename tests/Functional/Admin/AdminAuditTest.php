<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Content\Entity\Page;
use App\Core\Security\Enum\UserRole;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\DomCrawler\Crawler;

/** E15 PR-1: зміни через адмінку лягають у журнал з користувачем і джерелом admin_ui; журнал — лише читання; входи — у security_audit. */
final class AdminAuditTest extends AdminWebTestCase
{
    public function testCrudEditIsJournaledAndVisibleToManager(): void
    {
        $manager = $this->loginAs(UserRole::Manager, 'olena@example.test');

        $crawler = $this->client->request('GET', $this->adminUrl('/page/new'));
        $crawler = $this->submit($crawler, 'Page', [
            'Page[position]' => '3',
            'Page[translationsForm][uk][title]' => 'Доставка',
            'Page[translationsForm][uk][body]' => '<p>Нова пошта</p>',
        ]);
        self::assertFormRedirected($crawler);
        $this->em()->clear();
        $page = $this->em()->getRepository(Page::class)->findOneBy(['position' => 3]);
        self::assertNotNull($page);

        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/page/%d/edit', $page->getId())));
        $crawler = $this->submit($crawler, 'Page', ['Page[published]' => '1', 'Page[translationsForm][uk][title]' => 'Доставка і оплата'], edit: true);
        self::assertFormRedirected($crawler);

        $db = $this->em()->getConnection();
        $rows = $db->fetchAllAssociative('SELECT * FROM admin_audit_entry WHERE entity_type = ? AND entity_id = ? ORDER BY id', ['page', $page->getId()]);
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertSame(['admin_ui', $manager->getId(), 'Olena'], [$row['source'], (int) $row['user_id'], $row['actor']]);
            self::assertNotNull($row['ip'], 'IP анонімізований, але є');
            self::assertStringEndsWith('.0', (string) $row['ip']);
        }
        $updates = array_values(array_filter($rows, static fn (array $r): bool => 'update' === $r['action']));
        $fields = array_map(static fn (array $r): string => ($r['locale'] ?? '-').':'.$r['field'], $updates);
        self::assertContains('-:published', $fields);
        self::assertContains('uk:title', $fields);
        self::assertNotContains('uk:body', $fields, 'Незмінене поле не журналюється');
        self::assertContains('page:create', array_map(static fn (array $r): string => $r['entity_type'].':'.$r['action'], $rows));

        // менеджер бачить журнал і запис
        $crawler = $this->client->request('GET', $this->adminUrl('/admin-audit'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Журнал змін');
        self::assertStringContainsString('Доставка і оплата', $crawler->filter('table')->text());
        self::assertSelectorExists('a:contains("Журнал змін")', 'Пункт меню для менеджера');

        $this->client->request('GET', $this->adminUrl('/admin-audit/'.$updates[0]['id']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Було');

        // лише читання
        foreach (['/admin-audit/new', '/admin-audit/'.$updates[0]['id'].'/edit'] as $path) {
            $this->client->request('GET', $this->adminUrl($path));
            self::assertResponseStatusCodeSame(403, $path);
        }
        $this->client->request('POST', $this->adminUrl('/admin-audit/'.$updates[0]['id'].'/delete'));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(\count($rows), (int) $db->fetchOne('SELECT COUNT(*) FROM admin_audit_entry WHERE entity_type = ? AND entity_id = ?', ['page', $page->getId()]));
    }

    /** Рядки налаштувань магазину (setting) у журналі бачить лише адміністратор — як і самі налаштування (ADR D10). */
    public function testSettingRowsAreAdminOnly(): void
    {
        $db = $this->em()->getConnection();
        $db->executeStatement("INSERT INTO admin_audit_entry (occurred_at, source, action, entity_type, entity_id, entity_label, field, old_value, new_value) VALUES (NOW(), 'admin_ui', 'update', 'setting', 1, 'GTM container', 'value', '\"GTM-OLD\"', '\"GTM-NEW\"')");
        $id = (int) $db->lastInsertId();

        $this->loginAs(UserRole::Manager, 'olena@example.test');
        $crawler = $this->client->request('GET', $this->adminUrl('/admin-audit'));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('GTM-NEW', $crawler->filter('body')->text());
        $this->client->request('GET', $this->adminUrl('/admin-audit/'.$id));
        self::assertResponseStatusCodeSame(403);

        $this->loginAs(UserRole::Admin, 'admin@example.test');
        $crawler = $this->client->request('GET', $this->adminUrl('/admin-audit'));
        self::assertStringContainsString('GTM-NEW', $crawler->filter('table')->text());
        $this->client->request('GET', $this->adminUrl('/admin-audit/'.$id));
        self::assertResponseIsSuccessful();
    }

    public function testFilterByEntityAndSearchWork(): void
    {
        $this->loginAs(UserRole::Admin);
        $db = $this->em()->getConnection();
        $db->executeStatement("INSERT INTO admin_audit_entry (occurred_at, source, action, entity_type, entity_id, entity_label, field, old_value, new_value, actor) VALUES (NOW(), 'ai_api', 'update', 'product_variant', 42, 'SKU-42', 'priceMinor', '100', '200', 'Бот'), (NOW(), 'admin_ui', 'update', 'product', 7, 'Двері', 'featured', 'false', 'true', 'Олена')");

        $crawler = $this->client->request('GET', $this->adminUrl('/admin-audit'), ['filters' => ['source' => ['comparison' => '=', 'value' => 'ai_api']]]);
        self::assertResponseIsSuccessful();
        $table = $crawler->filter('table')->text();
        self::assertStringContainsString('SKU-42', $table);
        self::assertStringNotContainsString('Двері', $table);
        self::assertStringContainsString('AI (API)', $table, 'Джерело показано назвою, не кодом');
        self::assertStringNotContainsString('Null', $table, 'Порожні поля без бейджа Null');

        $crawler = $this->client->request('GET', $this->adminUrl('/admin-audit'), ['filters' => ['entityType' => ['comparison' => '=', 'value' => 'product']]]);
        self::assertResponseIsSuccessful();
        $table = $crawler->filter('table')->text();
        self::assertStringContainsString('Двері', $table);
        self::assertStringNotContainsString('SKU-42', $table);

        $crawler = $this->client->request('GET', $this->adminUrl('/admin-audit?query=Двері'));
        self::assertResponseIsSuccessful();
        $table = $crawler->filter('table')->text();
        self::assertStringContainsString('Двері', $table);
        self::assertStringNotContainsString('SKU-42', $table);
    }

    public function testAdminLoginAndLogoutGoToSecurityAudit(): void
    {
        $this->createUser('manager@example.test', UserRole::Manager);
        // один контейнер на всі запити, свій TestHandler на каналі security_audit
        $this->client->disableReboot();
        $logger = static::getContainer()->get('monolog.logger.security_audit');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($handler = new TestHandler());

        $crawler = $this->client->request('GET', $this->adminUrl('/login'));
        $this->client->submit($crawler->selectButton('Увійти')->form(['_username' => 'manager@example.test', '_password' => 'wrong']));
        $this->client->submit($crawler->selectButton('Увійти')->form(['_username' => 'manager@example.test', '_password' => 'secret123']));
        self::assertResponseRedirects($this->adminUrl('/'));
        $this->logout();

        $events = [];
        foreach ($handler->getRecords() as $record) {
            if (!str_starts_with($record->message, 'admin.')) {
                continue;
            }
            $events[] = $record->message;
            self::assertArrayNotHasKey('email', $record->context);
            self::assertArrayNotHasKey('password', $record->context);
            self::assertSame(16, \strlen((string) $record->context['email_hmac']));
            self::assertStringEndsWith('.0', (string) $record->context['ip'], 'IP анонімізований');
        }
        self::assertSame(['admin.login_failed', 'admin.login', 'admin.logout'], $events);
    }

    /** @param array<string, string> $values */
    private function submit(Crawler $crawler, string $formName, array $values, bool $edit = false): Crawler
    {
        $form = $crawler->filter(\sprintf('form[name="%s"]', $formName))->form();
        $form->setValues($values);
        $payload = $form->getPhpValues();
        $payload['ea'][$edit ? 'editForm' : 'newForm']['btn'] = 'saveAndReturn';

        return $this->client->request($form->getMethod(), $form->getUri(), $payload, $form->getPhpFiles());
    }
}
