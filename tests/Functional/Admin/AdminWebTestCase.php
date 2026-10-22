<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Адмінка живе на хості admin_host: усі запити — абсолютними URL на цей хост. */
abstract class AdminWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects(false);
    }

    protected function adminUrl(string $path = '/'): string
    {
        /** @var string $host */
        $host = static::getContainer()->getParameter('admin_host');

        return 'https://'.$host.$path;
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(string $email, UserRole $role, string $password = 'secret123', bool $active = true): User
    {
        $user = new User($email, ucfirst(strtok($email, '@') ?: 'user'), $role);
        $user->setActive($active);
        $user->setPasswordHash(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /** Редирект після сабміту; інакше — падіння з текстами помилок форми, щоб не шукати їх у HTML. */
    protected static function assertFormRedirected(Crawler $crawler): void
    {
        $response = static::getClient()?->getResponse();
        if (null !== $response && $response->isRedirect()) {
            return;
        }
        $errors = $crawler->filter('.invalid-feedback, .form-error-message, .alert-danger, .global-invalid-feedback')->each(static fn (Crawler $n): string => trim($n->text()));
        self::fail(\sprintf('Форму не прийнято (%d): %s', $response?->getStatusCode() ?? 0, implode(' | ', $errors) ?: 'без повідомлень'));
    }

    /** Вихід так, як це робить людина: посилання «Вийти» з меню користувача несе CSRF-токен (logout.enable_csrf). */
    protected function logout(): void
    {
        $crawler = $this->client->request('GET', $this->adminUrl('/'));
        self::assertResponseIsSuccessful();
        $this->client->click($crawler->selectLink('Вийти')->link());
        self::assertResponseRedirects($this->adminUrl('/login'));
    }

    protected function loginAs(UserRole $role, string $email = 'user@example.test'): User
    {
        $user = $this->createUser($email, $role);
        $this->client->loginUser($user, 'admin');

        return $user;
    }
}
