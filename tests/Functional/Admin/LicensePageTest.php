<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Security\Enum\UserRole;

/** Сторінка «Ліцензія» в адмінці (admin_license): лише ROLE_ADMIN, анонім — на вхід; рядок ліцензії у підвалі кожної сторінки адмінки. */
final class LicensePageTest extends AdminWebTestCase
{
    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', $this->adminUrl('/license'));
        self::assertResponseRedirects($this->adminUrl('/login'));
    }

    public function testManagerIsForbidden(): void
    {
        $this->loginAs(UserRole::Manager);
        $this->client->request('GET', $this->adminUrl('/license'));
        self::assertResponseStatusCodeSame(403);
        $crawler = $this->client->request('GET', $this->adminUrl('/'));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#main-menu a[href$="/license"]'), 'менеджер не бачить пункт меню «Ліцензія»');
        $footer = $crawler->filter('footer.app-license-footer');
        self::assertStringContainsString('· Ліцензія', $footer->text(), 'рядок ліцензії в підвалі є і для менеджера');
        self::assertCount(0, $footer->filter('a[href$="/license"]'), 'але без посилання на сторінку, яка дасть 403');
    }

    public function testAdminSeesLicenseDetailsTextAndComponents(): void
    {
        $this->loginAs(UserRole::Admin);
        $crawler = $this->client->request('GET', $this->adminUrl('/license'));
        self::assertResponseIsSuccessful();
        $container = static::getContainer();
        self::assertSame((string) $container->get(\App\Core\Shared\Site\SiteName::class), trim(explode('(', $crawler->filter('[data-licensee]')->text())[0]), 'без app.license власник = назва магазину');
        self::assertSame($container->getParameter('site_host'), $crawler->filter('[data-site-host]')->text());
        self::assertSame($container->getParameter('admin_host'), $crawler->filter('[data-admin-host]')->text());
        self::assertSame('Не вказано', $crawler->filter('[data-license-type]')->text());
        self::assertStringContainsString('Apache License 2.0', $crawler->filter('[data-code-license]')->text());
        self::assertSame('https://luxsite.agency/', $crawler->filter('[data-developers] a')->attr('href'));
        self::assertNotSame('', trim($crawler->filter('[data-engine-sha]')->text()), 'версія рушія: sha або «невідомо»');
        self::assertCount(0, $crawler->filter('[data-contract], [data-since]'), 'без app.license рядків договору й дати нема');
        self::assertSame('Ліцензія', $crawler->filter('#license-text h2')->text());
        self::assertStringContainsString('під ліцензією Apache 2.0', $crawler->filter('#license-text')->text());
        self::assertStringContainsString('Розробники платформи: LuxSite', $crawler->filter('#license-text')->text());
        self::assertStringContainsString('Symfony ecommerce LuxSite', $crawler->filter('#license-text')->text());
        self::assertStringContainsString($container->getParameter('site_host'), $crawler->filter('#license-text')->text(), '{site} підставлено');
        $rows = $crawler->filter('#license-components tbody tr');
        self::assertGreaterThan(50, \count($rows));
        self::assertStringContainsString('easycorp/easyadmin-bundle', implode(' ', $rows->each(static fn ($n): string => $n->text())));

        $crawler = $this->client->request('GET', $this->adminUrl('/'));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#main-menu a[href$="/license"]'), 'пункт меню «Ліцензія» у системному розділі');
    }

    public function testEveryAdminPageHasLicenseFooterLine(): void
    {
        $this->loginAs(UserRole::Admin);
        foreach (['/', '/product', '/setting', '/license'] as $path) {
            $crawler = $this->client->request('GET', $this->adminUrl($path));
            self::assertResponseIsSuccessful($path);
            $footer = $crawler->filter('footer.app-license-footer');
            self::assertCount(1, $footer, $path);
            self::assertStringContainsString('Symfony ecommerce LuxSite · відкритий код, Apache 2.0 · розробники платформи: LuxSite · Ліцензія', $footer->text(), $path);
            self::assertSame('/license', $footer->filter('a:contains("Ліцензія")')->attr('href'), $path);
            self::assertSame('https://luxsite.agency/', $footer->filter('a:contains("LuxSite")')->attr('href'), $path);
        }
        $this->logout();
        $crawler = $this->client->request('GET', $this->adminUrl('/login'));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('footer.app-license-footer'), 'рядок ліцензії і на сторінці входу');
        self::assertCount(0, $crawler->filter('footer.app-license-footer a[href$="/license"]'), 'анонімові посилання на сторінку не показується');
    }
}
