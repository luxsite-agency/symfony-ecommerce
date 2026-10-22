<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Admin\EventListener\AdminLocaleListener;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;

/**
 * Мова адмінки (AdminLocaleListener): типова на вході без сесії, вибір на сторінці входу переходить у профіль,
 * зміна в меню — у профіль, профіль — у нову сесію при вході; REST /api/admin сесії не відкриває.
 * Перемикач «UA / EN» (рішення власника 20.10.2026): видно у верхній панелі й на вході, поточна мова виділена; мова — на всіх
 * сторінках, вибір переживає вихід і вхід (cookie до входу, профіль після).
 */
final class AdminLocalePersistenceTest extends AdminWebTestCase
{
    public function testAnonymousLoginPageUsesDefaultAdminLocale(): void
    {
        $crawler = $this->client->request('GET', $this->adminUrl('/login'));
        self::assertResponseIsSuccessful();
        // тестове оточення: app.default_locale uk, адмінка uk перекладена → uk
        self::assertSame('uk', $crawler->filter('html')->attr('lang'));
        self::assertCount(1, $crawler->selectButton('Увійти'));
    }

    public function testLocaleChosenOnLoginPageSurvivesLoginAndIsStoredInProfile(): void
    {
        $user = $this->createUser('lena@example.test', UserRole::Manager);
        self::assertNull($user->getAdminLocale());

        $crawler = $this->client->request('GET', $this->adminUrl('/login?_locale=en'));
        self::assertResponseIsSuccessful();
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        $labels = implode(' | ', $crawler->filter('form label')->each(static fn ($n): string => trim($n->text())));
        self::assertStringContainsString('Email', $labels);
        self::assertStringContainsString('Password', $labels);
        self::assertCount(1, $crawler->selectButton('Sign in'), 'кнопка входу англійською');
        self::assertDoesNotMatchRegularExpression('/[\x{0400}-\x{04FF}]/u', $crawler->filter('form')->text(), 'форма входу без кирилиці');

        $this->client->submit($crawler->selectButton('Sign in')->form(['_username' => 'lena@example.test', '_password' => 'secret123']));
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('en', $crawler->filter('html')->attr('lang'), 'після входу — та сама мова');

        self::assertSame('en', $this->reloadUser($user)->getAdminLocale(), 'вибір на сторінці входу — у профіль');
    }

    public function testMenuSwitchIsStoredAndRestoredOnNextLogin(): void
    {
        $user = $this->createUser('dana@example.test', UserRole::Admin);
        $this->client->loginUser($user, 'admin');

        $crawler = $this->client->request('GET', $this->adminUrl('/?_locale=ru'));
        self::assertResponseIsSuccessful();
        self::assertSame('ru', $crawler->filter('html')->attr('lang'));
        self::assertSame('ru', $this->reloadUser($user)->getAdminLocale(), 'перемикач у меню — у профіль');

        // новий браузер: сесії нема, сторінка входу — типовою мовою, після входу — мова з профілю
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->followRedirects(false);
        $crawler = $this->client->request('GET', $this->adminUrl('/login'));
        self::assertSame('uk', $crawler->filter('html')->attr('lang'));
        $this->client->submit($crawler->selectButton('Увійти')->form(['_username' => 'dana@example.test', '_password' => 'secret123']));
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('ru', $crawler->filter('html')->attr('lang'), 'мова з профілю');
        self::assertSame('ru', $this->client->getSession()?->get(AdminLocaleListener::SESSION_KEY));

        $crawler = $this->client->request('GET', $this->adminUrl('/'));
        self::assertSame('ru', $crawler->filter('html')->attr('lang'), 'і далі з сесії');
    }

    public function testSwitcherIsVisibleOnLoginPageAndInTopBarWithCurrentLocaleHighlighted(): void
    {
        $crawler = $this->client->request('GET', $this->adminUrl('/login'));
        $switch = $crawler->filter('nav.admin-locale-switch');
        self::assertCount(1, $switch, 'перемикач на сторінці входу');
        self::assertSame('UA', trim($switch->filter('[aria-current="true"]')->text()), 'uk показується як UA, поточна виділена');
        self::assertCount(1, $switch->selectLink('EN'));
        self::assertStringStartsWith('UA / EN', trim(preg_replace('/\s+/', ' ', $switch->text()) ?? ''), 'текстом, без прапорів');

        $this->loginAs(UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl('/order?_locale=en'));
        $switch = $crawler->filter('.content-top .admin-locale-switch, .navbar-custom-menu .admin-locale-switch, nav.admin-locale-switch');
        self::assertGreaterThanOrEqual(1, $switch->count(), 'перемикач у верхній панелі');
        self::assertSame('EN', trim($switch->first()->filter('[aria-current="true"]')->text()));
        self::assertStringContainsString('_locale=uk', (string) $switch->first()->selectLink('UA')->attr('href'));
        self::assertStringContainsString('/order', (string) $switch->first()->selectLink('UA')->attr('href'), 'та сама сторінка');
    }

    public function testSwitchAppliesToEveryPageAndSurvivesLogoutAndLogin(): void
    {
        $user = $this->createUser('max@example.test', UserRole::Admin);
        $crawler = $this->client->request('GET', $this->adminUrl('/login'));
        $this->client->submit($crawler->selectButton('Увійти')->form(['_username' => 'max@example.test', '_password' => 'secret123']));
        $crawler = $this->client->followRedirect();
        self::assertSame('uk', $crawler->filter('html')->attr('lang'));

        // EN у верхній панелі — і всі сторінки англійською
        $this->client->click($crawler->filter('nav.admin-locale-switch')->selectLink('EN')->link());
        self::assertResponseIsSuccessful();
        foreach (['/', '/product', '/order', '/category', '/setting', '/license', '/admin-audit'] as $path) {
            $crawler = $this->client->request('GET', $this->adminUrl($path));
            self::assertResponseIsSuccessful($path);
            self::assertSame('en', $crawler->filter('html')->attr('lang'), $path);
            self::assertSame('EN', trim($crawler->filter('nav.admin-locale-switch [aria-current="true"]')->text()), $path);
        }
        self::assertSame('en', $this->reloadUser($user)->getAdminLocale());

        // вихід → сторінка входу тією ж мовою (cookie) → вхід → англійська
        $crawler = $this->logoutVia('Log out');
        self::assertSame('en', $crawler->filter('html')->attr('lang'), 'вхід після виходу — обрана мова');
        $this->client->submit($crawler->selectButton('Sign in')->form(['_username' => 'max@example.test', '_password' => 'secret123']));
        $crawler = $this->client->followRedirect();
        self::assertSame('en', $crawler->filter('html')->attr('lang'));

        // назад на UA — так само переживає вихід і вхід
        $crawler = $this->client->click($crawler->filter('nav.admin-locale-switch')->selectLink('UA')->link());
        self::assertSame('uk', $crawler->filter('html')->attr('lang'));
        $crawler = $this->logoutVia('Вийти');
        self::assertSame('uk', $crawler->filter('html')->attr('lang'));
        $this->client->submit($crawler->selectButton('Увійти')->form(['_username' => 'max@example.test', '_password' => 'secret123']));
        $crawler = $this->client->followRedirect();
        self::assertSame('uk', $crawler->filter('html')->attr('lang'));
        self::assertSame('uk', $this->reloadUser($user)->getAdminLocale());
    }

    public function testChoiceBeforeLoginIsRememberedInCookieAndMovesToProfile(): void
    {
        $user = $this->createUser('olya@example.test', UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl('/login'));
        $this->client->click($crawler->filter('nav.admin-locale-switch')->selectLink('EN')->link());
        self::assertSame('en', $this->client->getCookieJar()->get(AdminLocaleListener::COOKIE, '/', (string) parse_url($this->adminUrl(), \PHP_URL_HOST))?->getValue());

        // новий запит без ?_locale і без сесії-вибору — мова з cookie
        $this->client->getCookieJar()->expire((string) session_name());
        $this->client->getCookieJar()->expire('MOCKSESSID');
        $crawler = $this->client->request('GET', $this->adminUrl('/login'));
        self::assertSame('en', $crawler->filter('html')->attr('lang'), 'cookie до входу');
        $this->client->submit($crawler->selectButton('Sign in')->form(['_username' => 'olya@example.test', '_password' => 'secret123']));
        $crawler = $this->client->followRedirect();
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertSame('en', $this->reloadUser($user)->getAdminLocale(), 'вибір до входу — у профіль');
    }

    public function testUnsupportedLocaleIsIgnoredAndNotStored(): void
    {
        $user = $this->createUser('ivo@example.test', UserRole::Admin);
        $this->client->loginUser($user, 'admin');

        $crawler = $this->client->request('GET', $this->adminUrl('/?_locale=pl'));
        self::assertResponseIsSuccessful();
        self::assertSame('uk', $crawler->filter('html')->attr('lang'));
        self::assertNull($this->reloadUser($user)->getAdminLocale());
    }

    public function testAdminApiDoesNotStartSession(): void
    {
        $this->client->request('GET', $this->adminUrl('/api/admin/products?_locale=en'));
        $response = $this->client->getResponse();
        self::assertGreaterThanOrEqual(400, $response->getStatusCode(), 'без токена — відмова');
        self::assertSame([], $response->headers->getCookies(), 'stateless: без Set-Cookie сесії');
    }

    /** Вихід посиланням з меню користувача (CSRF-токен у посиланні) і сторінка входу, куди веде редирект. */
    private function logoutVia(string $label): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', $this->adminUrl('/'));
        $this->client->click($crawler->selectLink($label)->link());
        self::assertResponseRedirects($this->adminUrl('/login'));

        return $this->client->followRedirect();
    }

    private function reloadUser(User $user): User
    {
        $this->em()->clear();
        $fresh = $this->em()->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $fresh);

        return $fresh;
    }
}
