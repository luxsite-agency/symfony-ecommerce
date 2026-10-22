<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\CategoryTranslation;
use App\Core\Security\Enum\UserRole;

/** SEO п. 10: окремий H1 категорії в формі перекладів; порожній — назва. */
final class CategoryHeadingTest extends AdminWebTestCase
{
    public function testH1IsSavedPerLocaleAndFallsBackToName(): void
    {
        $this->loginAs(UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl('/category/new'));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="Category[translationsForm][uk][h1]"]'), 'Поле H1 у вкладці мови');
        $form = $crawler->filter('form[name="Category"]')->form();
        $form->setValues([
            'Category[translationsForm][uk][name]' => 'Футболки',
            'Category[translationsForm][uk][h1]' => 'Футболки з символікою Сокіл 77',
            'Category[translationsForm][ru][name]' => 'Футболки',
        ]);
        $payload = $form->getPhpValues();
        $payload['ea']['newForm']['btn'] = 'saveAndReturn';
        $crawler = $this->client->request($form->getMethod(), $form->getUri(), $payload, $form->getPhpFiles());
        self::assertFormRedirected($crawler);

        $this->em()->clear();
        $translation = $this->em()->getRepository(CategoryTranslation::class)->findOneBy(['locale' => 'uk', 'name' => 'Футболки']);
        self::assertNotNull($translation);
        self::assertSame('Футболки з символікою Сокіл 77', $translation->getH1());
        self::assertSame('Футболки з символікою Сокіл 77', $translation->getHeading());
        $ru = $translation->getCategory()->getTranslation('ru');
        self::assertInstanceOf(CategoryTranslation::class, $ru);
        self::assertNull($ru->getH1());
        self::assertSame('Футболки', $ru->getHeading(), 'Порожній h1 — назва');

        $category = $this->em()->find(Category::class, $translation->getCategory()->getId());
        self::assertNotNull($category);
        $category->translate('uk')->setH1('   ');
        $this->em()->flush();
        $this->em()->clear();
        self::assertNull($this->em()->getRepository(CategoryTranslation::class)->findOneBy(['locale' => 'uk', 'name' => 'Футболки'])?->getH1(), 'Пробіли = порожньо');
    }
}
