<?php

declare(strict_types=1);

namespace App\Tests\Core\Catalog;

use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Enum\AttributeType;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use PHPUnit\Framework\TestCase;

final class AttributeTest extends TestCase
{
    public function testSelectValuesAreUniqueAndOrdered(): void
    {
        $color = new Attribute('color', AttributeType::Select);
        $white = $color->addValue('white');
        $black = $color->addValue('black');

        self::assertSame(0, $white->getPosition());
        self::assertSame(1, $black->getPosition());
        self::assertSame($black, $color->findValue('black'));

        $this->expectException(CatalogInvariantViolation::class);
        $color->addValue('white');
    }

    public function testNumberAttributeHasNoValues(): void
    {
        $weight = new Attribute('shipping_weight', AttributeType::Number);

        $this->expectException(CatalogInvariantViolation::class);
        $weight->addValue('heavy');
    }

    public function testVariantAxisOnlyForSingleSelect(): void
    {
        (new Attribute('size', AttributeType::Select))->setVariantAxis(true);

        $this->expectException(CatalogInvariantViolation::class);
        (new Attribute('features', AttributeType::MultiSelect))->setVariantAxis(true);
    }
}
