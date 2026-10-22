<?php

declare(strict_types=1);

namespace App\Tests\Core\Catalog;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    public function testTreeNavigation(): void
    {
        $doors = new Category();
        $interior = new Category($doors);
        $glass = new Category($interior);

        self::assertSame([$doors, $interior], $glass->getAncestors());
        self::assertSame(2, $glass->getDepth());
        self::assertTrue($glass->isDescendantOf($doors));
        self::assertFalse($doors->isDescendantOf($glass));
        self::assertTrue($doors->getChildren()->contains($interior));
    }

    public function testMoveUpdatesBothParents(): void
    {
        $a = new Category();
        $b = new Category();
        $child = new Category($a);

        $child->setParent($b);

        self::assertFalse($a->getChildren()->contains($child));
        self::assertTrue($b->getChildren()->contains($child));
        self::assertSame($b, $child->getParent());
    }

    public function testCannotBeOwnParent(): void
    {
        $a = new Category();

        $this->expectException(CatalogInvariantViolation::class);
        $a->setParent($a);
    }

    public function testCannotMoveUnderOwnDescendant(): void
    {
        $root = new Category();
        $child = new Category($root);
        $grandchild = new Category($child);

        try {
            $root->setParent($grandchild);
            self::fail('Очікувався виняток про цикл');
        } catch (CatalogInvariantViolation) {
            self::assertNull($root->getParent(), 'Невдале перенесення не змінює дерево');
        }
    }

    public function testTranslationsByLocale(): void
    {
        $category = new Category();
        $category->translate('uk')->setName('Двері')->setSlug('dveri');
        $category->translate('RU')->setName('Двери')->setSlug('dveri');

        self::assertSame('Двері', $category->translate('uk')->getName());
        self::assertSame(['ru', 'uk'], $category->getTranslatedLocales());
        self::assertNull($category->getTranslation('en'));

        $category->removeTranslation('ru');
        self::assertSame(['uk'], $category->getTranslatedLocales());
    }
}
