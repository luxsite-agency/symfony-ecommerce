<?php

declare(strict_types=1);

namespace App\Tests\Core\Catalog\Synthetic;

use App\Core\Catalog\Enum\AttributeType;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Catalog\Synthetic\SyntheticCatalogGenerator;
use App\Core\Catalog\Synthetic\SyntheticCatalogSpec;
use App\Core\Catalog\Synthetic\SyntheticProduct;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Генератор синтетики: детермінованість за seed, дерево рівно з N вузлів, розподіли протоколу Ф1. */
final class SyntheticCatalogGeneratorTest extends TestCase
{
    public function testSameSeedGivesSameCatalogAndOtherSeedDiffers(): void
    {
        $spec = new SyntheticCatalogSpec(products: 300, seed: 42);
        $a = serialize(iterator_to_array((new SyntheticCatalogGenerator($spec))->products()));
        $b = serialize(iterator_to_array((new SyntheticCatalogGenerator($spec))->products()));
        self::assertSame($a, $b);

        $generator = new SyntheticCatalogGenerator($spec);
        self::assertSame($a, serialize(iterator_to_array($generator->products())), 'повторний обхід того ж генератора — той самий потік');
        mt_srand(1); // глобальний mt_rand не впливає
        self::assertSame($a, serialize(iterator_to_array($generator->products())));

        $other = serialize(iterator_to_array((new SyntheticCatalogGenerator(new SyntheticCatalogSpec(products: 300, seed: 43)))->products()));
        self::assertNotSame($a, $other);
    }

    public function testCategoryTreeHasExactlyRequestedNodesDepthThree(): void
    {
        $generator = new SyntheticCatalogGenerator(new SyntheticCatalogSpec(categories: 463));
        $categories = $generator->categories();
        self::assertCount(463, $categories);
        $byDepth = array_count_values(array_map(static fn ($c): int => $c->depth, $categories));
        self::assertSame([0 => 7, 1 => 49, 2 => 407], $byDepth);
        self::assertCount(407, $generator->leaves());
        foreach ($categories as $i => $category) {
            self::assertSame($i + 1, $category->index);
            self::assertTrue(null === $category->parent || $category->parent < $category->index, 'батько раніше за дитину');
        }

        foreach ([1, 2, 5, 13] as $n) {
            $small = new SyntheticCatalogGenerator(new SyntheticCatalogSpec(categories: $n));
            self::assertCount($n, $small->categories());
            self::assertNotEmpty($small->leaves());
        }
    }

    public function testDistributionsFollowProtocol(): void
    {
        $generator = new SyntheticCatalogGenerator(new SyntheticCatalogSpec(products: 5000, maxVariants: 4, categories: 463, attributes: 10, brands: 50, seed: 7));
        $attributes = $generator->attributes();
        self::assertCount(11, $attributes, '10 карткових + вісь');
        self::assertTrue($generator->axis()?->axis);
        self::assertSame([AttributeType::Select, AttributeType::Select, AttributeType::Number, AttributeType::Boolean], array_map(static fn ($a) => $a->type, \array_slice($attributes, 0, 4)));

        $variants = $tracked = $oos = $single = $inactive = 0;
        $perLeaf = [];
        /** @var SyntheticProduct $p */
        foreach ($generator->products() as $p) {
            self::assertGreaterThanOrEqual(1, \count($p->variants));
            self::assertLessThanOrEqual(4, \count($p->variants));
            $single += 1 === \count($p->variants) ? 1 : 0;
            $inactive += $p->active ? 0 : 1;
            $perLeaf[$p->categories[0]] = ($perLeaf[$p->categories[0]] ?? 0) + 1;
            self::assertContains($p->categories[0], $generator->leaves());
            self::assertArrayHasKey(1, $p->attributes, 'перша характеристика — 100 % покриття');
            $axisValues = [];
            foreach ($p->variants as $v) {
                ++$variants;
                if (StockPolicy::InStock === $v->policy) {
                    ++$tracked;
                    $oos += 0 === $v->stock ? 1 : 0;
                }
                if (null !== $v->axisValue) {
                    $axisValues[] = $v->axisValue;
                }
            }
            self::assertSame(\count($axisValues), \count(array_unique($axisValues)), 'значення осі в картці різні');
        }
        self::assertEqualsWithDelta(0.6, $single / 5000, 0.03);
        self::assertEqualsWithDelta(0.25, $oos / $tracked, 0.03);
        self::assertEqualsWithDelta(0.02, $inactive / 5000, 0.01);
        rsort($perLeaf);
        self::assertGreaterThan(20 * end($perLeaf), $perLeaf[0], 'Zipf: найбільший лист у рази більший за найменший');
    }

    public function testInvalidSpecIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SyntheticCatalogSpec(maxVariants: 13);
    }
}
