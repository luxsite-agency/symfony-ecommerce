<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Http\SecurityResponseHeaders;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Консиліум безпеки 10.10, п. 5: захисні заголовки з коду на кожній відповіді. Адмінка не вбудовується нікуди, сайт
 * лише в себе; кабінет і «дякуємо» зберігають свою Referrer-Policy; GTM і піксель лишаються в розмітці.
 */
final class SecurityHeadersTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testSiteHeaders(): void
    {
        $site = 'https://'.$this->param('site_host');
        foreach (['/', '/ru/', '/account/login', '/nothing-here-'.bin2hex(random_bytes(3)), '/sitemap.xml'] as $path) {
            $this->client->request('GET', $site.$path);
            $headers = $this->client->getResponse()->headers;
            self::assertSame('SAMEORIGIN', $headers->get('X-Frame-Options'), $path);
            self::assertSame("frame-ancestors 'self'", $headers->get('Content-Security-Policy'), $path);
            self::assertSame('nosniff', $headers->get('X-Content-Type-Options'), $path);
            self::assertSame(SecurityResponseHeaders::PERMISSIONS_POLICY, $headers->get('Permissions-Policy'), $path);
        }
        $this->client->request('GET', $site.'/');
        self::assertSame('strict-origin-when-cross-origin', $this->client->getResponse()->headers->get('Referrer-Policy'));
        $this->client->request('GET', $site.'/account/login');
        self::assertSame('strict-origin', $this->client->getResponse()->headers->get('Referrer-Policy'), 'кабінет лишає свою політику');
    }

    public function testAdminHeaders(): void
    {
        $admin = 'https://'.$this->param('admin_host');
        $this->client->request('GET', $admin.'/login');
        self::assertResponseIsSuccessful();
        $this->assertAdminHeaders('/login');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('headers-'.bin2hex(random_bytes(3)).'@example.test', 'Менеджер', UserRole::Manager);
        $user->setPasswordHash(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $em->persist($user);
        $em->flush();
        $this->client->loginUser($user, 'admin');
        foreach (['/', '/order', '/wholesale-application'] as $path) {
            $this->client->request('GET', $admin.$path);
            self::assertResponseIsSuccessful($path);
            $this->assertAdminHeaders($path);
        }
        // редирект без входу — теж із заголовками (DENY ставиться на будь-яку відповідь адмінки)
        $this->client->request('GET', $admin.'/logout');
        $this->assertAdminHeaders('/logout');
    }

    /** frame-ancestors обмежує, хто вбудовує нас; GTM (noscript-iframe) і піксель Meta на сторінці лишаються. */
    public function testAnalyticsTagsStillRender(): void
    {
        $settings = static::getContainer()->get(Settings::class);
        $settings->set(SettingKeys::GTM_CONTAINER_ID, 'GTM-TEST123');
        $settings->set(SettingKeys::FB_PIXEL_ID, '1234567890');
        $settings->set(SettingKeys::TAGS_ON_DEV, '1');
        $html = (string) $this->client->request('GET', 'https://'.$this->param('site_host').'/')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('googletagmanager.com/ns.html?id=GTM-TEST123', $html);
        self::assertStringContainsString('fbq(', $html);
        self::assertSame("frame-ancestors 'self'", $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    private function assertAdminHeaders(string $path): void
    {
        $headers = $this->client->getResponse()->headers;
        self::assertSame('DENY', $headers->get('X-Frame-Options'), $path);
        self::assertSame("frame-ancestors 'none'", $headers->get('Content-Security-Policy'), $path);
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'), $path);
        self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'), $path);
        self::assertSame(SecurityResponseHeaders::PERMISSIONS_POLICY, $headers->get('Permissions-Policy'), $path);
    }

    private function param(string $name): string
    {
        /** @var string $value */
        $value = static::getContainer()->getParameter($name);

        return $value;
    }
}
