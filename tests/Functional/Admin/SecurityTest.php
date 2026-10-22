<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Security\Enum\UserRole;

/** Вхід в адмінку: лише на хості admin_host, лише активні користувачі, через form login з CSRF. */
final class SecurityTest extends AdminWebTestCase
{
    public function testAdminRoutesDoNotExistOnStoreHost(): void
    {
        $this->client->request('GET', 'https://shop-engine.localhost/login');
        self::assertResponseStatusCodeSame(404);

        // EasyAdmin не ставить host на CRUD-маршрути — AdminHostGuard віддає 404 поза admin_host (інакше CRUD був би без входу)
        foreach (['/product', '/user', '/page', '/product/new', '/library'] as $path) {
            $this->client->request('GET', 'https://shop-engine.localhost'.$path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', $this->adminUrl('/'));
        self::assertResponseRedirects($this->adminUrl('/login'));
    }

    public function testLoginWithValidCredentials(): void
    {
        $this->createUser('manager@example.test', UserRole::Manager);

        $crawler = $this->client->request('GET', $this->adminUrl('/login'));
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Увійти')->form(['_username' => 'manager@example.test', '_password' => 'secret123']);
        $this->client->submit($form);
        self::assertResponseRedirects($this->adminUrl('/'));

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Manager');
    }

    public function testIdleAdminSessionExpires(): void
    {
        $this->createUser('manager@example.test', UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl('/login'));
        $this->client->submit($crawler->selectButton('Увійти')->form(['_username' => 'manager@example.test', '_password' => 'secret123']));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        // остання активність — 2 год 1 хв тому (app.admin_idle_timeout = 7200): mock-сесія тестів лежить файлом
        $id = $this->client->getCookieJar()->get('MOCKSESSID')?->getValue();
        self::assertNotNull($id);
        $file = static::getContainer()->getParameter('kernel.cache_dir').'/sessions/'.$id.'.mocksess';
        $data = unserialize((string) file_get_contents($file));
        $data['_sf2_meta']['u'] = time() - 7260;
        file_put_contents($file, serialize($data));

        $this->client->request('GET', $this->adminUrl('/'));
        self::assertResponseRedirects($this->adminUrl('/login'), null, 'бездіяльність понад 2 год — вхід заново');
    }

    public function testActiveAdminSessionSurvives(): void
    {
        $this->createUser('manager@example.test', UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl('/login'));
        $this->client->submit($crawler->selectButton('Увійти')->form(['_username' => 'manager@example.test', '_password' => 'secret123']));
        $this->client->followRedirect();
        $id = (string) $this->client->getCookieJar()->get('MOCKSESSID')?->getValue();
        $file = static::getContainer()->getParameter('kernel.cache_dir').'/sessions/'.$id.'.mocksess';
        $data = unserialize((string) file_get_contents($file));
        $data['_sf2_meta']['u'] = time() - 7000;
        file_put_contents($file, serialize($data));

        $this->client->request('GET', $this->adminUrl('/'));
        self::assertResponseIsSuccessful('1 год 57 хв — ще в адмінці');
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $this->createUser('manager@example.test', UserRole::Manager);

        $crawler = $this->client->request('GET', $this->adminUrl('/login'));
        $this->client->submit($crawler->selectButton('Увійти')->form(['_username' => 'manager@example.test', '_password' => 'wrong']));
        self::assertResponseRedirects($this->adminUrl('/login'));

        $crawler = $this->client->followRedirect();
        self::assertGreaterThan(0, $crawler->filter('.alert-danger, .invalid-feedback, [class*="error"]')->count(), 'Помилка входу має бути показана');
    }

    public function testInactiveUserCannotLogin(): void
    {
        $this->createUser('off@example.test', UserRole::Admin, active: false);

        $crawler = $this->client->request('GET', $this->adminUrl('/login'));
        $this->client->submit($crawler->selectButton('Увійти')->form(['_username' => 'off@example.test', '_password' => 'secret123']));
        self::assertResponseRedirects($this->adminUrl('/login'));

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'вимкнено');
    }

    /** Вихід лише з CSRF-токеном (консиліум безпеки 10.10, п. 9): голий /logout з чужої сторінки не розлогінює. */
    public function testLogoutNeedsCsrfToken(): void
    {
        $this->loginAs(UserRole::Manager);
        $this->client->request('GET', $this->adminUrl('/'));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->adminUrl('/logout'));
        self::assertResponseStatusCodeSame(403, 'без токена — відмова');
        $this->client->request('GET', $this->adminUrl('/logout?_csrf_token=wrong'));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->adminUrl('/'));
        self::assertResponseIsSuccessful('сесія жива');

        $this->logout();
        $this->client->request('GET', $this->adminUrl('/'));
        self::assertResponseRedirects($this->adminUrl('/login'));
    }
}
