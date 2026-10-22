<?php

declare(strict_types=1);

namespace App\Tests\Integration\Core\Catalog\FilterIndex;

use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\AttributeValue;
use App\Core\Catalog\Entity\Brand;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\AttributeType;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Каталог для тестів індексу й запитів (коди з суфіксом — щоб не зіткнутися з даними інших тестів):
 *
 *   shoes ⊃ sneakers ⊃ running;  bags
 *   P1 nike  running   V1(42, red, 1000, 5 шт)  V2(38, red, 1200, 3 шт)   leather+mesh, 0.8 кг, waterproof
 *   P2 puma  sneakers  V3(42, blue, 1500, 2 шт)                           mesh, 1.2 кг, не waterproof
 *   P3 nike  sneakers  V4(40, red, 900, 0 шт — не купити)  V5(41, blue, 950, під замовлення) leather
 *   P4 puma  sneakers  V6(42, red, 500, 9 шт) — ТОВАР ВИМКНЕНО
 *   P5 —     bags      default, ціна за запитом, 2.0 кг
 *   P6 nike  shoes     default (без осей), 3000, 1 шт, leather
 * Приклад критика (P1{V1(42,red), V2(38,red)}, P2{V3(42,blue)}) — підмножина.
 */
final class CatalogIndexFixture
{
    public Attribute $size;
    public Attribute $color;
    public Attribute $material;
    public Attribute $weight;
    public Attribute $waterproof;
    public Attribute $hidden;
    /** @var array<string, AttributeValue> */
    public array $opt = [];
    public Category $shoes;
    public Category $sneakers;
    public Category $running;
    public Category $bags;
    public Brand $nike;
    public Brand $puma;
    /** @var array<string, Product> P1…P6 */
    public array $p = [];
    /** @var array<string, ProductVariant> V1…V6 */
    public array $v = [];

    public function __construct(private readonly EntityManagerInterface $em, private readonly string $suffix)
    {
        $this->size = $this->attribute('size', AttributeType::Select, axis: true);
        $this->color = $this->attribute('color', AttributeType::Select, axis: true);
        $this->material = $this->attribute('material', AttributeType::MultiSelect);
        $this->weight = $this->attribute('weight', AttributeType::Number);
        $this->waterproof = $this->attribute('waterproof', AttributeType::Boolean);
        $this->hidden = $this->attribute('hidden', AttributeType::Select, filterable: false);
        foreach (['38', '40', '41', '42'] as $code) {
            $this->opt[$code] = $this->size->addValue('s'.$code);
        }
        foreach (['red', 'blue'] as $code) {
            $this->opt[$code] = $this->color->addValue($code);
        }
        foreach (['leather', 'mesh'] as $code) {
            $this->opt[$code] = $this->material->addValue($code);
        }
        $this->opt['secret'] = $this->hidden->addValue('secret');

        $this->shoes = $this->category('shoes');
        $this->sneakers = $this->category('sneakers', $this->shoes);
        $this->running = $this->category('running', $this->sneakers);
        $this->bags = $this->category('bags');
        $this->nike = new Brand('Nike '.$suffix, 'nike-'.$suffix);
        $this->puma = new Brand('Puma '.$suffix, 'puma-'.$suffix);
        $em->persist($this->nike);
        $em->persist($this->puma);

        $p1 = $this->product('P1', $this->running, $this->nike);
        $this->variant($p1, 'V1', '42', 'red', 1000, '5');
        $this->variant($p1, 'V2', '38', 'red', 1200, '3');
        $p1->addAttributeOption($this->opt['leather']);
        $p1->addAttributeOption($this->opt['mesh']);
        $p1->setAttributeNumber($this->weight, '0.8');
        $p1->setAttributeBoolean($this->waterproof, true);
        $p1->addAttributeOption($this->opt['secret']);

        $p2 = $this->product('P2', $this->sneakers, $this->puma);
        $this->variant($p2, 'V3', '42', 'blue', 1500, '2');
        $p2->addAttributeOption($this->opt['mesh']);
        $p2->setAttributeNumber($this->weight, '1.2');
        $p2->setAttributeBoolean($this->waterproof, false);

        $p3 = $this->product('P3', $this->sneakers, $this->nike);
        $this->variant($p3, 'V4', '40', 'red', 900, '0');
        $v5 = $this->variant($p3, 'V5', '41', 'blue', 950, '0');
        $v5->setStockPolicy(StockPolicy::MadeToOrder);
        $p3->addAttributeOption($this->opt['leather']);

        $p4 = $this->product('P4', $this->sneakers, $this->puma);
        $this->variant($p4, 'V6', '42', 'red', 500, '9');
        $p4->setActive(false);

        $p5 = $this->product('P5', $this->bags, null);
        $p5->getDefaultVariant()->setPrice(Money::of(0, 'UAH'));
        $p5->setPriceMode(PriceMode::OnRequest);
        $p5->setAttributeNumber($this->weight, '2.0');

        $p6 = $this->product('P6', $this->shoes, $this->nike);
        $p6->getDefaultVariant()->setPrice(Money::of(3000, 'UAH'));
        $p6->getDefaultVariant()->setStock('1');
        $p6->addAttributeOption($this->opt['leather']);

        $em->flush();
    }

    /** @return list<int> */
    public function productIds(): array
    {
        return array_values(array_map(static fn (Product $p): int => (int) $p->getId(), $this->p));
    }

    public function id(string $name): int
    {
        return (int) $this->p[$name]->getId();
    }

    public function optionId(string $code): int
    {
        return (int) $this->opt[$code]->getId();
    }

    private function attribute(string $code, AttributeType $type, bool $axis = false, bool $filterable = true): Attribute
    {
        $attribute = new Attribute($code.'_'.$this->suffix, $type);
        $attribute->setFilterable($filterable);
        if ($axis) {
            $attribute->setVariantAxis(true);
        }
        $this->em->persist($attribute);

        return $attribute;
    }

    private function category(string $slug, ?Category $parent = null): Category
    {
        $category = new Category($parent);
        $category->translate('uk')->setName($slug)->setSlug($slug.'-'.$this->suffix);
        $this->em->persist($category);

        return $category;
    }

    private function product(string $name, Category $category, ?Brand $brand): Product
    {
        $product = new Product($name.'-'.$this->suffix, 'UAH');
        $product->translate('uk')->setName($name)->setSlug(strtolower($name).'-'.$this->suffix);
        $product->addCategory($category);
        $product->setBrand($brand);
        $this->em->persist($product);

        return $this->p[$name] = $product;
    }

    /** Перший варіант товару — наявний default (перейменовується), решта — нові. */
    private function variant(Product $product, string $name, string $size, string $color, int $price, string $stock): ProductVariant
    {
        $default = $product->getDefaultVariant();
        $variant = [] === $default->getAxisValues()->toArray() && !\in_array($default, $this->v, true)
            ? $default
            : $product->addVariant($name.'-'.$this->suffix);
        $variant->setAxisValue($this->opt[$size]);
        $variant->setAxisValue($this->opt[$color]);
        $variant->setPrice(Money::of($price, 'UAH'));
        $variant->setStock($stock);

        return $this->v[$name] = $variant;
    }
}
