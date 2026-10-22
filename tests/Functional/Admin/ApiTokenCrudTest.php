<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\AdminApi\AdminApiTokens;
use App\Core\AdminApi\ApiScope;
use App\Core\AdminApi\Entity\AdminApiToken;
use App\Core\Security\Enum\UserRole;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Сторінка «API-токени» (E15 PR-2): менеджер створює собі й бачить лише свої, значення — один раз; адмін бачить усі
 * й відкликає; new/edit/delete вимкнені; відкликання — POST з CSRF; деактивація користувача гасить його токени.
 */
final class ApiTokenCrudTest extends AdminWebTestCase
{
    public function testManagerIssuesSeesOnceAndRevokesOwn(): void
    {
        $olena = $this->loginAs(UserRole::Manager, 'olena@example.test');

        $crawler = $this->client->request('GET', $this->adminUrl('/admin-api-token'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a:contains("API-токени")', 'пункт меню');
        self::assertSelectorExists('a:contains("Створити токен")');

        $crawler = $this->client->click($crawler->selectLink('Створити токен')->link());
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form#api-token-issue')->form();
        // чотири дозволи, четвертий — живий дизайн (20.10.2026): код дозволу дрібно поруч з назвою
        self::assertCount(\count(ApiScope::cases()), $crawler->filter('input[name="new_api_token[scopes][]"]'), 'чекбокс на кожен скоуп');
        self::assertSame(1, $crawler->filter('input[name="new_api_token[scopes][]"][value="theme:write"]')->count(), 'чекбокс theme:write');
        $form['new_api_token[name]'] = 'Claude Code';
        $form['new_api_token[scopes]'][0]->tick();
        $form['new_api_token[days]'] = '45';
        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $plain = $crawler->filter('#api-token-plain input')->attr('value');
        self::assertIsString($plain);
        self::assertMatchesRegularExpression('/^shp_[A-Za-z0-9_-]{43}$/', $plain);
        self::assertStringContainsString(substr($plain, 0, 12).'…', $crawler->filter('table')->text());
        self::assertStringContainsString('catalog:read', $crawler->filter('table')->text());

        // удруге — лише префікс
        $crawler = $this->client->request('GET', $this->adminUrl('/admin-api-token'));
        self::assertSelectorNotExists('#api-token-plain');
        self::assertStringNotContainsString($plain, (string) $this->client->getResponse()->getContent());

        $token = $this->em()->getRepository(AdminApiToken::class)->findOneBy(['name' => 'Claude Code']);
        self::assertNotNull($token);
        self::assertSame($olena->getId(), $token->getUser()->getId());
        self::assertEqualsWithDelta(45 * 86400, $token->getExpiresAt()->getTimestamp() - time(), 3700);

        // стандартні дії вимкнені
        foreach (['/admin-api-token/new', '/admin-api-token/'.$token->getId().'/edit'] as $path) {
            $this->client->request('GET', $this->adminUrl($path));
            self::assertResponseStatusCodeSame(403, $path);
        }
        $this->client->request('POST', $this->adminUrl('/admin-api-token/'.$token->getId().'/delete'));
        self::assertResponseStatusCodeSame(403);

        // відкликання — лише POST з CSRF
        $this->client->request('POST', $this->adminUrl('/admin-api-token/revoke'), ['id' => $token->getId(), 'token' => 'wrong']);
        self::assertResponseRedirects();
        self::assertFalse($this->reload($token)->isRevoked(), 'без CSRF не відкликано');
        $this->revokeViaForm($crawler, $token);
        self::assertTrue($this->reload($token)->isRevoked());
        $crawler = $this->client->request('GET', $this->adminUrl('/admin-api-token'));
        self::assertStringContainsString('Відкликано', $crawler->filter('table')->text());
        self::assertCount(0, $crawler->filter('a.action-revoke'), 'відкликаний — кнопки нема');
    }

    public function testManagersAreIsolatedAndAdminSeesAll(): void
    {
        $tokens = static::getContainer()->get(AdminApiTokens::class);
        $olena = $this->createUser('olena@example.test', UserRole::Manager);
        $ihor = $this->createUser('ihor@example.test', UserRole::Manager);
        $t1 = $tokens->issue($olena, 'olena-token', [ApiScope::CatalogRead], null, $olena)->token;
        $t2 = $tokens->issue($ihor, 'ihor-token', [ApiScope::PricesWrite], null, $ihor)->token;

        $this->client->loginUser($ihor, 'admin');
        $crawler = $this->client->request('GET', $this->adminUrl('/admin-api-token'));
        $table = $crawler->filter('table')->text();
        self::assertStringContainsString('ihor-token', $table);
        self::assertStringNotContainsString('olena-token', $table, 'чужих токенів менеджер не бачить');
        self::assertStringNotContainsString('Власник', $crawler->filter('thead')->text());
        // чужий — не відкликає: ні сторінка підтвердження, ні POST з CSRF зі своєї сторінки; і не відкриває за URL (рев'ю C #73 L2)
        $this->client->request('GET', $this->adminUrl('/admin-api-token/'.$t1->getId()));
        self::assertResponseStatusCodeSame(403, 'detail вимкнено');
        $this->client->request('GET', $this->adminUrl('/admin-api-token/revoke?entityId='.$t1->getId()));
        self::assertResponseStatusCodeSame(403);
        $own = $this->client->request('GET', $this->adminUrl('/admin-api-token/revoke?entityId='.$t2->getId()));
        self::assertResponseIsSuccessful();
        $csrf = $own->filter('#api-token-revoke input[name="token"]')->attr('value');
        $this->client->request('POST', $this->adminUrl('/admin-api-token/revoke'), ['id' => $t1->getId(), 'token' => $csrf]);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reload($t1)->isRevoked());

        $this->client->loginUser($this->createUser('admin@example.test', UserRole::Admin), 'admin');
        $crawler = $this->client->request('GET', $this->adminUrl('/admin-api-token'));
        $table = $crawler->filter('table')->text();
        self::assertStringContainsString('olena-token', $table);
        self::assertStringContainsString('ihor-token', $table);
        self::assertStringContainsString('Власник', $crawler->filter('thead')->text());
        $this->revokeViaForm($crawler, $t1);
        self::assertTrue($this->reload($t1)->isRevoked());
        self::assertFalse($this->reload($t2)->isRevoked());
    }

    public function testDeactivatingUserRevokesTokens(): void
    {
        $tokens = static::getContainer()->get(AdminApiTokens::class);
        $olena = $this->createUser('olena@example.test', UserRole::Manager);
        $token = $tokens->issue($olena, 'olena-token', [ApiScope::CatalogRead], null, $olena)->token;
        $this->loginAs(UserRole::Admin, 'admin@example.test');

        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/user/%d/edit', $olena->getId())));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="User"]')->form();
        $active = $form->get('User[active]');
        self::assertInstanceOf(ChoiceFormField::class, $active);
        $active->untick();
        $payload = $form->getPhpValues();
        $payload['ea']['editForm']['btn'] = 'saveAndReturn';
        $this->client->request($form->getMethod(), $form->getUri(), $payload);
        self::assertResponseRedirects();

        $this->em()->clear();
        $fresh = $this->em()->find(AdminApiToken::class, $token->getId());
        self::assertNotNull($fresh);
        self::assertTrue($fresh->isRevoked(), 'деактивація користувача відкликає його токени');
    }

    private function reload(AdminApiToken $token): AdminApiToken
    {
        $this->em()->clear();
        $fresh = $this->em()->find(AdminApiToken::class, $token->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }

    /** Як людина: посилання «Відкликати» з рядка → сторінка підтвердження → POST з CSRF. */
    private function revokeViaForm(Crawler $crawler, AdminApiToken $token): void
    {
        $link = $crawler->filter(\sprintf('a.action-revoke[href*="entityId=%d"]', $token->getId()));
        self::assertCount(1, $link, 'посилання «Відкликати» в рядку');
        $confirm = $this->client->click($link->link());
        self::assertResponseIsSuccessful();
        $this->client->submit($confirm->filter('form#api-token-revoke')->form());
        self::assertResponseRedirects();
    }
}
