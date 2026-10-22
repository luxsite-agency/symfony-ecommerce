<?php

declare(strict_types=1);

namespace App\Tests\Core\Catalog;

use App\Core\Catalog\Entity\Media;
use App\Core\Integration\Entity\ExternalRef;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExternalRefAndMediaTest extends TestCase
{
    public function testExternalRef(): void
    {
        $ref = new ExternalRef('keycrm', 'product_variant', 42, ' 1017 ');

        self::assertSame('1017', $ref->getExternalId());
        self::assertSame('product_variant', $ref->getEntityType());
    }

    public function testExternalRefRequiresPersistedEntity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExternalRef('keycrm', 'product_variant', 0, '1017');
    }

    public function testExternalRefSystemIsMachineCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExternalRef('Key CRM', 'order', 1, '5');
    }

    public function testMediaPathCannotEscapeStorage(): void
    {
        self::assertSame('products/a.webp', (new Media('/products/a.webp', 'image/webp', 1))->getPath());

        $this->expectException(InvalidArgumentException::class);
        new Media('../../etc/passwd', 'text/plain', 1);
    }

    public function testMediaDimensionsComeInPairs(): void
    {
        $media = new Media('a.jpg', 'image/jpeg', 1);
        $media->setDimensions(800, 600);
        self::assertSame(600, $media->getHeight());

        $this->expectException(InvalidArgumentException::class);
        $media->setDimensions(800, null);
    }
}
