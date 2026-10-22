<?php

declare(strict_types=1);

namespace App\Tests\Functional\AdminApi;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\Post;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Фікстури для REST і сторінки пакетів (E15 PR-4): дві категорії, товар ML-14 (4262.00 UAH, uk-переклад, опис з HTML),
 * сторінка «Доставка», запис блогу, група wholesale. Повертає id.
 */
trait CatalogFixtures
{
    /** @return array{doorsId: int, handlesId: int, productId: int, variantId: int, pageId: int, postId: int} */
    protected static function seedCatalog(EntityManagerInterface $em): array
    {
        $doors = new Category();
        $doors->translate('uk')->setName('Двері')->setSlug('dveri');
        $handles = new Category($doors);
        $handles->translate('uk')->setName('Ручки')->setSlug('ruchky');
        $product = new Product('ML-14', 'UAH');
        $product->translate('uk')->setName('Двері ML 14')->setSlug('dveri-ml-14')->setShortDescription('Сталеві двері')->setDescription('<p>Опис <b>жирний</b></p>');
        $product->addCategory($doors);
        $product->getDefaultVariant()->setPrice(Money::of(426_200, 'UAH'));
        $page = new Page();
        $page->setPublished(true);
        $page->translate('uk')->setTitle('Доставка')->setSlug('dostavka')->setBody('<p>Текст</p>');
        $post = new Post();
        $post->translate('uk')->setTitle('Новина')->setSlug('novyna')->setBody('<p>Текст новини</p>');
        $group = new CustomerGroup('wholesale', 'Опт');
        foreach ([$doors, $handles, $product, $page, $post, $group] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [
            'doorsId' => (int) $doors->getId(),
            'handlesId' => (int) $handles->getId(),
            'productId' => (int) $product->getId(),
            'variantId' => (int) $product->getDefaultVariant()->getId(),
            'pageId' => (int) $page->getId(),
            'postId' => (int) $post->getId(),
        ];
    }
}
