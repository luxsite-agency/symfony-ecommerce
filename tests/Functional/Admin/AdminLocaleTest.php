<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Security\Enum\UserRole;

/** Перемикач мови адмінки (?_locale=xx) застосовується і запамʼятовується в сесії; чужа мова ігнорується. */
final class AdminLocaleTest extends AdminWebTestCase
{
    public function testLocaleSwitchAppliesAndPersists(): void
    {
        $this->loginAs(UserRole::Admin);

        $this->client->request('GET', $this->adminUrl('/?_locale=en'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Dashboard', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Дашборд', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', $this->adminUrl('/'));
        self::assertStringContainsString('Dashboard', (string) $this->client->getResponse()->getContent(), 'мова з сесії');
        self::assertStringNotContainsString('Дашборд', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', $this->adminUrl('/?_locale=xx'));
        self::assertStringContainsString('Dashboard', (string) $this->client->getResponse()->getContent(), 'невідома мова ігнорується');

        $this->client->request('GET', $this->adminUrl('/?_locale=uk'));
        self::assertStringContainsString('Дашборд', (string) $this->client->getResponse()->getContent());
    }
}
