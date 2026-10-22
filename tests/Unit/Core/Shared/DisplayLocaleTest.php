<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Shared;

use App\Core\Catalog\Entity\Category;
use App\Core\Shared\Translation\DisplayLocale;
use PHPUnit\Framework\TestCase;

/** __toString перекладних сутностей: мова показу → мова за замовчуванням → будь-яка непорожня (не «перша завантажена»). */
final class DisplayLocaleTest extends TestCase
{
    protected function tearDown(): void
    {
        DisplayLocale::set(null, 'uk');
    }

    public function testPicksDisplayThenDefaultThenAny(): void
    {
        $category = new Category();
        $category->translate('ru')->setName('Двери');
        $category->translate('uk')->setName('Двері');
        $category->translate('en')->setName('');

        DisplayLocale::set('uk', 'uk');
        self::assertSame('Двері', (string) $category, 'ru створено першим, але адмінка uk');
        DisplayLocale::set('ru', 'uk');
        self::assertSame('Двери', (string) $category);
        DisplayLocale::set('en', 'uk');
        self::assertSame('Двері', (string) $category, 'en порожній — мова за замовчуванням');

        $onlyRu = new Category();
        $onlyRu->translate('ru')->setName('Только ru');
        self::assertSame('Только ru', (string) $onlyRu, 'нема ні en, ні uk — будь-яка непорожня');
    }
}
