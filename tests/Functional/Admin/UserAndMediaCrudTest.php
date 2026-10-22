<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Catalog\Entity\Media;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Користувачі (хеш пароля з форми) і медіатека (завантаження через Vich). */
final class UserAndMediaCrudTest extends AdminWebTestCase
{
    public function testAdminCreatesManagerWithHashedPassword(): void
    {
        $this->loginAs(UserRole::Admin, 'admin@example.test');
        $crawler = $this->client->request('GET', $this->adminUrl('/user/new'));
        $crawler = $this->submit($crawler, 'User', [
            'User[email]' => 'Manager@Example.test',
            'User[displayName]' => 'Олена',
            'User[role]' => 'ROLE_MANAGER',
            'User[plainPassword]' => 'manager-pass-1',
        ]);
        self::assertFormRedirected($crawler);

        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'manager@example.test']);
        self::assertNotNull($user, 'Пошта нормалізується до нижнього регістру');
        self::assertSame(UserRole::Manager, $user->getRole());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'manager-pass-1'));
    }

    public function testShortPasswordIsRejected(): void
    {
        $this->loginAs(UserRole::Admin, 'admin@example.test');
        $crawler = $this->client->request('GET', $this->adminUrl('/user/new'));
        $this->submit($crawler, 'User', [
            'User[email]' => 'x@example.test',
            'User[displayName]' => 'X',
            'User[role]' => 'ROLE_MANAGER',
            'User[plainPassword]' => 'short',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => 'x@example.test']));
    }

    public function testUploadImageToLibrary(): void
    {
        $this->loginAs(UserRole::Manager);
        $png = tempnam(sys_get_temp_dir(), 'img').'.png';
        $image = imagecreatetruecolor(40, 30);
        self::assertNotFalse($image);
        imagepng($image, $png);

        $crawler = $this->client->request('GET', $this->adminUrl('/library/new'));
        $form = $crawler->filter('form[name="Media"]')->form();
        $form['Media[alt]'] = 'Двері ML 14, білі';
        $file = $form->get('Media[file][file]');
        self::assertInstanceOf(FileFormField::class, $file);
        $file->upload($png);
        $payload = $form->getPhpValues();
        $payload['ea']['newForm']['btn'] = 'saveAndReturn';
        $crawler = $this->client->request($form->getMethod(), $form->getUri(), $payload, $form->getPhpFiles());
        self::assertFormRedirected($crawler);

        $media = $this->em()->getRepository(Media::class)->findOneBy(['alt' => 'Двері ML 14, білі']);
        self::assertNotNull($media);
        self::assertStringEndsWith('.png', $media->getPath());
        self::assertSame('image/png', $media->getMimeType());
        self::assertSame([40, 30], [$media->getWidth(), $media->getHeight()]);
        self::assertGreaterThan(0, $media->getSizeBytes());

        $stored = static::getContainer()->getParameter('kernel.project_dir').'/public/media/'.$media->getPath();
        self::assertFileExists($stored);
        @unlink($stored);
        @unlink($png);
    }

    /** @param array<string, string> $values */
    private function submit(Crawler $crawler, string $formName, array $values): Crawler
    {
        $form = $crawler->filter(\sprintf('form[name="%s"]', $formName))->form();
        $form->setValues($values);
        $payload = $form->getPhpValues();
        $payload['ea']['newForm']['btn'] = 'saveAndReturn';

        return $this->client->request($form->getMethod(), $form->getUri(), $payload, $form->getPhpFiles());
    }
}
