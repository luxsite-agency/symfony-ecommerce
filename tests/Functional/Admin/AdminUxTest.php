<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Core\Catalog\Entity\Brand;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Media;
use App\Core\Catalog\Entity\Product;
use App\Core\Security\Enum\UserRole;

/** Зручності адмінки (частина А): кнопки форм, JS-ассети, фільтри списку товарів, масові дії, вітрина головної. */
final class AdminUxTest extends AdminWebTestCase
{
    public function testFormButtonsAndAssets(): void
    {
        $this->loginAs(UserRole::Manager);
        $crawler = $this->client->request('GET', $this->adminUrl('/brand/new'));
        self::assertResponseIsSuccessful();
        $buttons = $crawler->filter('body')->text();
        self::assertStringContainsString('Вийти без збереження', $buttons);
        self::assertStringContainsString('Зберегти і створити ще', $buttons);
        self::assertSame($this->adminUrl('/brand'), $crawler->filter('a:contains("Вийти без збереження")')->link()->getUri());
        self::assertCount(1, $crawler->filter('script[src*="/assets/admin-tabs.js"]'));
        self::assertCount(1, $crawler->filter('script[src*="/assets/admin-collection.js"]'));

        $brand = new Brand('Solo', 'solo');
        $this->em()->persist($brand);
        $this->em()->flush();
        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/brand/%d/edit', $brand->getId())));
        self::assertStringContainsString('Зберегти і продовжити редагування', $crawler->filter('body')->text());
    }

    public function testProductFiltersByCategoryBrandActiveAndStock(): void
    {
        $this->loginAs(UserRole::Manager);
        $doors = new Category();
        $doors->translate('uk')->setName('Двері')->setSlug('dveri');
        $solo = new Brand('Solo', 'solo');
        $a = new Product('IN-STOCK', 'UAH');
        $a->translate('uk')->setName('Є в наявності');
        $a->getDefaultVariant()->setStock('5');
        $a->addCategory($doors);
        $a->setBrand($solo);
        $b = new Product('OUT-STOCK', 'UAH');
        $b->translate('uk')->setName('Нема');
        $c = new Product('INACTIVE', 'UAH');
        $c->translate('uk')->setName('Вимкнений');
        $c->setActive(false);
        $c->getDefaultVariant()->setStock('9');
        foreach ([$doors, $solo, $a, $b, $c] as $e) {
            $this->em()->persist($e);
        }
        $this->em()->flush();

        $skus = fn (string $query): array => array_values(array_filter(array_map(
            static fn (string $sku): string => trim($sku),
            $this->client->request('GET', $this->adminUrl('/product?'.$query))->filter('table tbody tr td[data-column="sku"]')->each(static fn ($n): string => $n->text()),
        )));

        self::assertEqualsCanonicalizing(['IN-STOCK', 'OUT-STOCK', 'INACTIVE'], $skus(''));
        // імена полів — як у формі фільтрів EasyAdmin (/product/render-filters): boolean — filters[x]=1|0, entity — comparison + value
        self::assertSame(['IN-STOCK'], $skus(\sprintf('filters[categories][comparison]==&filters[categories][value][]=%d', $doors->getId())));
        self::assertSame(['IN-STOCK'], $skus(\sprintf('filters[brand][comparison]==&filters[brand][value]=%d', $solo->getId())));
        self::assertSame(['INACTIVE'], $skus('filters[active]=0'));
        self::assertEqualsCanonicalizing(['IN-STOCK', 'INACTIVE'], $skus('filters[inStock]=1'));
        self::assertSame(['OUT-STOCK'], $skus('filters[inStock]=0'));
        self::assertSame(['IN-STOCK'], $skus('filters[inStock]=1&filters[active]=1'));
    }

    public function testBatchActivateAndDeactivate(): void
    {
        $this->loginAs(UserRole::Manager);
        $a = new Product('B-1', 'UAH');
        $b = new Product('B-2', 'UAH');
        $b->setActive(false);
        $this->em()->persist($a);
        $this->em()->persist($b);
        $this->em()->flush();

        $crawler = $this->client->request('GET', $this->adminUrl('/product'));
        $button = $crawler->filter('[data-action-name="batchDeactivate"]');
        self::assertCount(1, $button, 'Масова дія «Деактивувати» є в списку');
        $this->client->request('POST', $button->attr('data-action-url') ?? '', [
            'batchActionName' => 'batchDeactivate',
            'entityFqcn' => Product::class,
            'batchActionUrl' => $button->attr('data-action-url'),
            'batchActionCsrfToken' => $button->attr('data-action-csrf-token'),
            'batchActionEntityIds' => [(string) $a->getId(), (string) $b->getId()],
        ]);
        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertFalse($this->em()->find(Product::class, $a->getId())?->isActive());
        self::assertFalse($this->em()->find(Product::class, $b->getId())?->isActive());

        $crawler = $this->client->request('GET', $this->adminUrl('/product'));
        $button = $crawler->filter('[data-action-name="batchActivate"]');
        $this->client->request('POST', $button->attr('data-action-url') ?? '', [
            'batchActionName' => 'batchActivate',
            'entityFqcn' => Product::class,
            'batchActionUrl' => $button->attr('data-action-url'),
            'batchActionCsrfToken' => $button->attr('data-action-csrf-token'),
            'batchActionEntityIds' => [(string) $b->getId()],
        ]);
        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertTrue($this->em()->find(Product::class, $b->getId())?->isActive());
        self::assertFalse($this->em()->find(Product::class, $a->getId())?->isActive());
    }

    public function testBatchFeatureUnfeatureAndFilter(): void
    {
        $this->loginAs(UserRole::Manager);
        $a = new Product('F-1', 'UAH');
        $b = new Product('F-2', 'UAH');
        $this->em()->persist($a);
        $this->em()->persist($b);
        $this->em()->flush();

        $this->batch('batchFeature', [(int) $a->getId(), (int) $b->getId()]);
        $this->em()->clear();
        self::assertTrue($this->em()->find(Product::class, $a->getId())?->isFeatured());
        self::assertTrue($this->em()->find(Product::class, $b->getId())?->isFeatured());

        $this->batch('batchUnfeature', [(int) $b->getId()]);
        $this->em()->clear();
        self::assertTrue($this->em()->find(Product::class, $a->getId())?->isFeatured());
        self::assertFalse($this->em()->find(Product::class, $b->getId())?->isFeatured());

        $skus = fn (string $query): array => array_values(array_filter(array_map(
            static fn (string $sku): string => trim($sku),
            $this->client->request('GET', $this->adminUrl('/product?'.$query))->filter('table tbody tr td[data-column="sku"]')->each(static fn ($n): string => $n->text()),
        )));
        self::assertSame(['F-1'], $skus('filters[featured]=1'), 'фільтр «На головній»');
        self::assertSame(['F-2'], $skus('filters[featured]=0'));

        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/product/%d/edit', $a->getId())));
        self::assertCount(1, $crawler->filter('input[type="checkbox"][name="Product[featured]"][checked]'), 'прапорець у формі товару');
    }

    public function testCategoryImageFromMediaLibrary(): void
    {
        $this->loginAs(UserRole::Manager);
        $category = new Category();
        $category->translate('uk')->setName('Шеврони')->setSlug('shevrony');
        $media = new Media('categories/chevrons.jpg', 'image/jpeg', 1000);
        $this->em()->persist($category);
        $this->em()->persist($media);
        $this->em()->flush();

        $crawler = $this->client->request('GET', $this->adminUrl(\sprintf('/category/%d/edit', $category->getId())));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(\sprintf('select[name="Category[image]"] option[value="%d"]', $media->getId())), 'вибір зображення з медіатеки');
        $form = $crawler->filter('form[name="Category"]')->form();
        $form->setValues(['Category[image]' => (string) $media->getId()]);
        $payload = $form->getPhpValues();
        $payload['ea']['editForm']['btn'] = 'saveAndReturn';
        self::assertFormRedirected($this->client->request($form->getMethod(), $form->getUri(), $payload));

        $this->em()->clear();
        self::assertSame('categories/chevrons.jpg', $this->em()->find(Category::class, $category->getId())?->getImage()?->getPath());
    }

    /** @param list<int> $ids */
    private function batch(string $action, array $ids): void
    {
        $button = $this->client->request('GET', $this->adminUrl('/product'))->filter(\sprintf('[data-action-name="%s"]', $action));
        self::assertCount(1, $button, \sprintf('масова дія %s є в списку', $action));
        $this->client->request('POST', $button->attr('data-action-url') ?? '', [
            'batchActionName' => $action,
            'entityFqcn' => Product::class,
            'batchActionUrl' => $button->attr('data-action-url'),
            'batchActionCsrfToken' => $button->attr('data-action-csrf-token'),
            'batchActionEntityIds' => array_map(strval(...), $ids),
        ]);
        self::assertResponseRedirects();
    }
}
