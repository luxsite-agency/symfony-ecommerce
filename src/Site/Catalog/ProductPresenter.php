<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Catalog;

use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Media;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductAttributeValue;
use App\Core\Catalog\Entity\ProductMedia;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\AttributeType;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\PriceQuote;
use App\Core\Pricing\Pricing;
use App\Core\Shared\Money\Money;
use App\Core\Shared\Quantity\Quantity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Дані сторінки товару: активні варіанти й перемикач осей (VariantMatrix), обраний варіант (ціна, стара ціна,
 * наявність, SKU), характеристики картки мовою сторінки, зображення (спершу обраного варіанта, далі спільні), крихти.
 * Наявність — та сама семантика, що в read-index: «в наявності» лише облікований залишок > 0; під замовлення —
 * made_to_order; не облікований (послуги) — «доступно». Ціна — за групою покупця ($group з CustomerContext); для групи
 * поруч роздрібна (retailPrice), якщо інша. SEO-мета й schema беруть роздріб (retailPrice ?? price).
 *
 * @phpstan-type AxisView array{code: string, label: string, options: list<array{label: string, selected: bool, available: bool, exact: bool, query: array<string, string>}>}
 */
final readonly class ProductPresenter
{
    public const string AVAILABLE = 'available';

    public function __construct(private TranslatorInterface $translator, private EntityManagerInterface $em, private Pricing $pricing)
    {
    }

    /**
     * @param array<array-key, mixed> $query GET сторінки
     *
     * @return array{variant: ?ProductVariant, availability: string, price: ?Money, oldPrice: ?Money, retailPrice: ?Money, quote: ?PriceQuote, priceFrom: bool, axes: list<AxisView>, attributes: list<array{label: string, value: string}>, images: list<Media>, crumbs: list<Category>, categories: list<Category>}
     */
    public function present(Product $product, string $locale, array $query, ?CustomerGroup $group): array
    {
        $this->preload($product);
        /** @var list<ProductVariant> $variants */
        $variants = array_values(array_filter($product->getVariants()->toArray(), static fn (ProductVariant $v): bool => $v->isActive()));
        [$matrix, $axisLabels] = $this->matrix($variants, $locale);

        $requested = [];
        foreach ($matrix->axes as $axis) {
            if (\is_string($query[$axis['code']] ?? null)) {
                $requested[$axis['code']] = $query[$axis['code']];
            }
        }
        $key = $matrix->select($requested);
        $variant = null;
        foreach ($variants as $candidate) {
            if ($candidate->getId() === $key) {
                $variant = $candidate;
            }
        }

        $axes = [];
        if (null !== $key) {
            foreach ($matrix->choices($key) as $choice) {
                $options = [];
                foreach ($choice['options'] as $option) {
                    $options[] = [
                        'label' => $axisLabels[$choice['code']]['options'][$option['value']] ?? $option['value'],
                        'selected' => $option['selected'], 'available' => $option['available'], 'exact' => $option['exact'],
                        'query' => $option['target'],
                    ];
                }
                $axes[] = ['code' => $choice['code'], 'label' => $axisLabels[$choice['code']]['label'] ?? $choice['code'], 'options' => $options];
            }
        }

        $onRequest = PriceMode::OnRequest === $product->getPriceMode();
        // ціна з фасаду (Ф3): опції за замовчуванням + акції, як порахує кошик для рядка без вибору; якщо акція діє —
        // перекреслена ціна = ціна до акції, інакше ручна oldPrice варіанта
        $quote = null !== $variant && !$onRequest ? $this->pricing->cardQuote($variant, $locale, $group) : null;
        $retail = null !== $variant && null !== $quote && null !== $group ? $this->pricing->cardQuote($variant, $locale, null)->price : null;
        // те саме правило, що у фідах (PriceQuote::compareAt): «стара» ціна не буває меншою за нову
        $oldPrice = null !== $variant && null !== $quote ? $quote->compareAt($variant->getOldPrice()) : null;

        return [
            'variant' => $variant,
            'availability' => null !== $variant ? self::availability($variant) : ProductCards::OUT,
            'price' => $quote?->price,
            'oldPrice' => $oldPrice,
            'retailPrice' => null !== $retail && !$retail->equals($quote->price) ? $retail : null,
            'quote' => $quote,
            'priceFrom' => PriceMode::From === $product->getPriceMode(),
            'axes' => $axes,
            'attributes' => $this->attributes($product, $locale),
            'images' => self::images($product, $variant),
            'crumbs' => self::crumbs($product),
            'categories' => self::categories($product),
        ];
    }

    /**
     * Три fetch-join запити замість N+1 лінивих (варіанти → осі → значення → переклади, характеристики → переклади,
     * медіа): сутності лягають в identity map, далі шаблон і матриця читають їх без запитів.
     */
    private function preload(Product $product): void
    {
        $this->em->createQueryBuilder()->select('v', 'av', 'val', 'vt', 'a', 'at')->from(ProductVariant::class, 'v')
            ->leftJoin('v.axisValues', 'av')->leftJoin('av.value', 'val')->leftJoin('val.translations', 'vt')
            ->leftJoin('av.attribute', 'a')->leftJoin('a.translations', 'at')
            ->where('v.product = :product')->setParameter('product', $product)->getQuery()->getResult();
        $this->em->createQueryBuilder()->select('pav', 'a', 'at', 'o', 'ot')->from(ProductAttributeValue::class, 'pav')
            ->join('pav.attribute', 'a')->leftJoin('a.translations', 'at')->leftJoin('pav.option', 'o')->leftJoin('o.translations', 'ot')
            ->where('pav.product = :product')->setParameter('product', $product)->getQuery()->getResult();
        $this->em->createQueryBuilder()->select('pm', 'm')->from(ProductMedia::class, 'pm')->join('pm.media', 'm')
            ->where('pm.product = :product')->setParameter('product', $product)->getQuery()->getResult();
    }

    /**
     * «В наявності» — коли із залишку можна купити мінімальну кількість (кошик і PlaceOrder не дадуть менше): 5 шт. при
     * мінімумі 10 — «немає». Те саме правило у фідах (MerchantFeedGenerator), тож наявність на сторінці й у фіді збігається.
     */
    public static function availability(ProductVariant $variant): string
    {
        $inStock = Quantity::compare($variant->getStock(), '0') > 0 && Quantity::compare($variant->getStock(), $variant->getMinQuantity()) >= 0;

        return match ($variant->getStockPolicy()) {
            StockPolicy::InStock => $inStock ? ProductCards::AVAILABLE : ProductCards::OUT,
            StockPolicy::MadeToOrder => ProductCards::TO_ORDER,
            StockPolicy::NotTracked => self::AVAILABLE,
        };
    }

    /**
     * @param list<ProductVariant> $variants
     *
     * @return array{VariantMatrix, array<string, array{label: string, options: array<string, string>}>}
     */
    private function matrix(array $variants, string $locale): array
    {
        /** @var array<int, Attribute> $attributes */
        $attributes = [];
        /** @var array<int, array<string, array{int, int, string}>> $values attribute id → code → [position, id, label] */
        $values = [];
        $rows = [];
        foreach ($variants as $variant) {
            $axes = [];
            foreach ($variant->getAxisValues() as $axisValue) {
                $attribute = $axisValue->getAttribute();
                $value = $axisValue->getValue();
                $attributes[(int) $attribute->getId()] = $attribute;
                $values[(int) $attribute->getId()][$value->getCode()] = [$value->getPosition(), (int) $value->getId(), $value->getTranslation($locale)?->getValue() ?? $value->getCode()];
                $axes[$attribute->getCode()] = $value->getCode();
            }
            ksort($axes); // стабільний порядок параметрів у посиланнях перемикача
            $rows[] = ['key' => (int) $variant->getId(), 'axes' => $axes, 'available' => ProductCards::OUT !== self::availability($variant)];
        }
        uasort($attributes, static fn (Attribute $a, Attribute $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        $axes = [];
        $labels = [];
        foreach ($attributes as $id => $attribute) {
            $options = $values[$id];
            uasort($options, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            $axes[] = ['code' => $attribute->getCode(), 'options' => array_map('strval', array_keys($options))];
            $labels[$attribute->getCode()] = [
                'label' => $attribute->getTranslation($locale)?->getName() ?? $attribute->getCode(),
                'options' => array_map(static fn (array $o): string => $o[2], $options),
            ];
        }

        return [new VariantMatrix($axes, $rows), $labels];
    }

    /** @return list<array{label: string, value: string}> характеристики картки в порядку position, значення multiselect — через кому */
    private function attributes(Product $product, string $locale): array
    {
        /** @var array<int, array{Attribute, list<string>}> $grouped */
        $grouped = [];
        foreach ($product->getAttributeValues() as $value) {
            $attribute = $value->getAttribute();
            $grouped[(int) $attribute->getId()][0] = $attribute;
            $grouped[(int) $attribute->getId()][1][] = $this->valueLabel($value, $locale);
        }
        uasort($grouped, static fn (array $a, array $b): int => [$a[0]->getPosition(), $a[0]->getId()] <=> [$b[0]->getPosition(), $b[0]->getId()]);

        return array_values(array_map(static fn (array $g): array => [
            'label' => $g[0]->getTranslation($locale)?->getName() ?? $g[0]->getCode(),
            'value' => implode(', ', $g[1]),
        ], $grouped));
    }

    private function valueLabel(ProductAttributeValue $value, string $locale): string
    {
        return match ($value->getType()) {
            AttributeType::Boolean => $this->translator->trans($value->getBooleanValue() ? 'catalog.flag_yes' : 'catalog.flag_no', [], FacetBlockBuilder::DOMAIN, $locale),
            AttributeType::Number => trim(self::number((string) $value->getNumberValue()).' '.$this->unit($value->getAttribute()->getUnit(), $locale)),
            default => $value->getOption()?->getTranslation($locale)?->getValue() ?? $value->getOption()?->getCode() ?? '',
        };
    }

    /** Одиниця характеристики мовою вітрини (домен units: «мм» → «mm»); невідома — як задана в адмінці. */
    private function unit(?string $unit, string $locale): string
    {
        return null === $unit ? '' : $this->translator->trans($unit, [], 'units', $locale);
    }

    /** "0.8000" → "0.8", "12.0000" → "12". */
    private static function number(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    /** @return list<Media> спершу зображення обраного варіанта, далі спільні товару; зображення інших варіантів — ні */
    private static function images(Product $product, ?ProductVariant $variant): array
    {
        $own = $shared = [];
        foreach ($product->getMedia() as $link) {
            if (null === $link->getVariant()) {
                $shared[] = $link->getMedia();
            } elseif ($link->getVariant() === $variant) {
                $own[] = $link->getMedia();
            }
        }

        return [...$own, ...$shared];
    }

    /** @return list<Category> від кореня до категорії товару (головна або перша) */
    private static function crumbs(Product $product): array
    {
        $category = $product->getMainCategory() ?? $product->getCategories()->first();
        if (!$category instanceof Category) {
            return [];
        }

        return [...$category->getAncestors(), $category];
    }

    /**
     * Усі активні категорії товару для блоку «Категорії товару» (ТЗ SEO: посилання з картки на кожну категорію):
     * головна першою, далі решта в порядку привʼязки, без дублів. Неактивну категорію або з неактивним предком пропускаємо.
     *
     * @return list<Category>
     */
    private static function categories(Product $product): array
    {
        $main = $product->getMainCategory();
        $all = $product->getCategories()->toArray();
        if (null !== $main) {
            array_unshift($all, $main);
        }
        $seen = [];
        $result = [];
        foreach ($all as $category) {
            $key = spl_object_id($category);
            if (isset($seen[$key]) || !$category->isActive()) {
                continue;
            }
            $seen[$key] = true;
            foreach ($category->getAncestors() as $ancestor) {
                if (!$ancestor->isActive()) {
                    continue 2;
                }
            }
            $result[] = $category;
        }

        return $result;
    }
}
