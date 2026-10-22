<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Demo;

use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\AttributeValue;
use App\Core\Catalog\Entity\Brand;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\CategoryTranslation;
use App\Core\Catalog\Entity\Media;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductMedia;
use App\Core\Catalog\Entity\ProductTranslation;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\AttributeType;
use App\Core\Catalog\Enum\OptionSelection;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Enum\ProductKind;
use App\Core\Catalog\Enum\SalesUnit;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Catalog\FilterIndex\CategorySummaryRefresher;
use App\Core\Catalog\FilterIndex\FilterIndexRebuilder;
use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\PageTranslation;
use App\Core\Content\Entity\Post;
use App\Core\Content\Entity\PostCategory;
use App\Core\Content\Entity\PostTranslation;
use App\Core\Content\Entity\Solution;
use App\Core\Content\Entity\SolutionOption;
use App\Core\Content\Entity\SolutionTranslation;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Pricing\Promotion\DoctrinePromotionProvider;
use App\Core\Pricing\Promotion\Entity\Promotion;
use App\Core\Settings\SettingKeys;
use App\Core\Settings\Settings;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Демо-наповнення магазину (команда app:demo:seed): каталог з DemoCatalog/DemoTexts, контент, налаштування,
 * замовлення гостей по останніх ~180 днях (DemoOrderPlans), демо-покупці з їхніми замовленнями (DemoCustomers, лише в
 * демо-режимі) і наприкінці — перебудова індексу фільтрів і зведення фасетів.
 *
 * Ідемпотентно: кожна сутність шукається за своєю позначкою (SKU DEMO-…, код demo_…, слаг demo-… мовою за
 * замовчуванням, акція за назвою «DEMO: …») і створюється лише якщо її нема. У наявних оновлюються лише переклади, які
 * сідер сам колись заповнив заглушкою (DemoLocales::isStale: нема, стара позначка « [xx]», копія тексту мови за
 * замовчуванням), і порядок демо-сторінок; правлене в адмінці не чіпається. Решта — --purge і повторний запуск.
 *
 * @phpstan-import-type ProductSpec from DemoCatalog
 * @phpstan-import-type VariantSpec from DemoCatalog
 */
final class DemoSeeder
{
    /** Підпис статей демо-блогу до 20.10.2026: поле одномовне, тож тепер статті без автора, а старий підпис прибирається. */
    private const string LEGACY_AUTHOR = 'Редакція демо-магазину';

    /** @var array<string, int> */
    private array $report = [];
    /** @var array<string, Attribute> */
    private array $attributes = [];
    /** @var array<string, Category> */
    private array $categories = [];
    /** @var array<string, Brand> */
    private array $brands = [];
    /** @var array<string, Product> ключ DemoCatalog → товар (лише створені в цьому запуску або знайдені) */
    private array $products = [];
    private ?CustomerGroup $wholesale = null;
    /** Фото поточного запуску: app.demo.images_dir або --images-dir; задається в seed(), не тягнеться між викликами. */
    private DemoPhotos $photos;
    /** @var callable(string): void */
    private $log;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DemoLocales $locales,
        private readonly DemoImageFactory $images,
        private readonly DemoPhotos $defaultPhotos,
        private readonly Settings $settings,
        private readonly DemoOrderFactory $orders,
        private readonly DemoCustomers $customers,
        private readonly FilterIndexRebuilder $filterIndex,
        private readonly CategorySummaryRefresher $summaries,
        private readonly DoctrinePromotionProvider $promotions,
        #[Autowire('%app.default_currency%')] private readonly string $currency,
        private readonly CacheManager $imagineCache,
    ) {
        $this->photos = $defaultPhotos;
        $this->log = static function (string $_): void {};
    }

    /**
     * @param callable(string): void|null $log
     * @param string|null                 $imagesDir тека фото замість app.demo.images_dir (опція --images-dir)
     *
     * @return array<string, int> що створено (ключ — тип сутності)
     */
    public function seed(bool $withImages = true, bool $withOrders = true, ?callable $log = null, ?string $imagesDir = null): array
    {
        $log ??= static function (string $_): void {};
        $this->log = $log;
        $this->photos = null === $imagesDir ? $this->defaultPhotos : $this->defaultPhotos->withDir($imagesDir);
        $this->report = [];
        $this->locales->takeMissing(); // лічильник — лише за цей запуск

        $log('Характеристики, бренди, групи покупців');
        $this->seedAttributes();
        $this->seedBrands();
        $this->seedCustomerGroup();
        $this->em->flush();

        $log('Категорії');
        $this->seedCategories();
        $this->em->flush();
        if ($withImages) {
            $this->syncCategoryImages();
            $this->em->flush();
        }

        $log('Товари');
        $this->seedProducts($withImages);

        $log('Акції');
        $this->seedPromotions();
        $this->em->flush();
        $this->promotions->reset(); // демо-замовлення нижче рахуються вже з акціями

        $log('Сторінки й блог');
        $this->seedPages();
        $this->seedSolution();
        $this->seedBlog($withImages);
        $this->em->flush();

        $log('Налаштування');
        $this->seedSettings();

        if ($withOrders) {
            $log('Замовлення');
            $this->seedOrders();
            $log('Демо-покупці');
            foreach ($this->customers->seed() as $what => $n) {
                $this->count($what, $n);
            }
        }

        $log('Індекс фільтрів і зведення фасетів');
        $this->filterIndex->rebuild();
        $this->summaries->refreshAll();

        foreach ($this->locales->takeMissing() as $locale => $n) {
            $log(\sprintf('  ! %s: %d текстів без перекладу, взято мову за замовчуванням (%s) — допишіть у DemoTexts/DemoCatalog', $locale, $n, $this->locales->default()));
        }

        return $this->report;
    }

    private function count(string $what, int $n = 1): void
    {
        $this->report[$what] = ($this->report[$what] ?? 0) + $n;
    }

    // --- довідники ---

    private function seedAttributes(): void
    {
        $position = 100;
        foreach (DemoCatalog::attributes() as $key => $spec) {
            $code = DemoCatalog::CODE_PREFIX.$key;
            $attribute = $this->em->getRepository(Attribute::class)->findOneBy(['code' => $code]);
            if (null === $attribute) {
                $attribute = new Attribute($code, AttributeType::from($spec['type']));
                $attribute->setUnit($spec['unit'] ?? null);
                $attribute->setFilterable($spec['filterable']);
                $attribute->setVariantAxis($spec['axis']);
                $attribute->setPosition($position);
                $this->em->persist($attribute);
                $this->count('attributes');
            }
            foreach ($this->locales->all() as $locale) {
                if ($this->locales->isStale($attribute->getTranslation($locale)?->getName(), $spec['name'], $locale)) {
                    $attribute->translate($locale)->setName($this->locales->text($spec['name'], $locale));
                    $this->countRefresh($attribute->getId());
                }
            }
            foreach ($spec['values'] ?? [] as $valueCode => $label) {
                $value = $attribute->findValue($valueCode);
                if (null === $value) {
                    $value = $attribute->addValue($valueCode);
                    $this->count('attribute_values');
                }
                foreach ($this->locales->all() as $locale) {
                    if ($this->locales->isStale($value->getTranslation($locale)?->getValue(), $label, $locale)) {
                        $value->translate($locale)->setValue($this->locales->text($label, $locale));
                        $this->countRefresh($value->getId());
                    }
                }
            }
            $this->attributes[$key] = $attribute;
            $position += 10;
        }
    }

    private function seedBrands(): void
    {
        foreach (DemoCatalog::brands() as $key => $name) {
            $slug = DemoCatalog::SLUG_PREFIX.$key;
            $brand = $this->em->getRepository(Brand::class)->findOneBy(['slug' => $slug]);
            if (null === $brand) {
                $brand = new Brand($name, $slug);
                $this->em->persist($brand);
                $this->count('brands');
            }
            $this->brands[$key] = $brand;
        }
    }

    private function seedCustomerGroup(): void
    {
        $group = $this->em->getRepository(CustomerGroup::class)->findOneBy(['code' => DemoCatalog::CUSTOMER_GROUP]);
        if (null === $group) {
            $group = new CustomerGroup(DemoCatalog::CUSTOMER_GROUP, 'Оптовий');
            $this->em->persist($group);
            $this->count('customer_groups');
        }
        // група, засіяна до E13 PR-7, цих полів не має; нічне скидання чіпає лише покупців, тож дописуємо тут (рев'ю #66 v2 L1),
        // а виставлене руками в адмінці не перезаписуємо
        $group->setExactStock(true); // оптовик демо бачить точні залишки (E13 PR-7)
        if (null === $group->getMinOrderAmountMinor()) {
            $group->setMinOrderAmount(self::uah(DemoCatalog::WHOLESALE_MIN_ORDER, $this->currency)); // мінімум замовлення групи (оптові демо-замовлення більші)
        }
        $this->wholesale = $group;
    }

    private function seedCategories(): void
    {
        $texts = DemoTexts::categories();
        $position = 0;
        foreach (DemoCatalog::categories() as $key => $spec) {
            $slug = $this->locales->slug($texts[$key]['name'], $this->locales->default(), DemoCatalog::SLUG_PREFIX);
            $existing = $this->em->getRepository(CategoryTranslation::class)->findOneBy(['locale' => $this->locales->default(), 'slug' => $slug]);
            if (null !== $existing) {
                $this->categories[$key] = $existing->getCategory();
                $this->translateCategory($existing->getCategory(), $texts[$key]);
                continue;
            }
            $category = new Category(null === $spec['parent'] ? null : $this->categories[$spec['parent']]);
            $category->setPosition($position++);
            $this->translateCategory($category, $texts[$key]);
            foreach ($spec['filters'] as $i => $attributeKey) {
                $category->addFilterAttribute($this->attributes[$attributeKey])->setPosition($i);
            }
            $this->em->persist($category);
            $this->categories[$key] = $category;
            $this->count('categories');
        }
    }

    /** @param array{name: array<string, string>, description: array<string, string>} $text */
    private function translateCategory(Category $category, array $text): void
    {
        foreach ($this->locales->all() as $locale) {
            if (!$this->locales->isStale($category->getTranslation($locale)?->getName(), $text['name'], $locale)) {
                continue;
            }
            $category->translate($locale)
                ->setName($this->locales->text($text['name'], $locale))
                ->setSlug($this->locales->slug($text['name'], $locale, DemoCatalog::SLUG_PREFIX))
                ->setDescription($this->locales->paragraph($text['description'], $locale));
            $this->countRefresh($category->getId());
        }
    }

    /** Оновлений переклад наявного запису (у нового id ще нема — це не оновлення, а створення). */
    private function countRefresh(?int $id): void
    {
        if (null !== $id) {
            $this->count('translations_updated');
        }
    }

    // --- товари ---

    private function seedProducts(bool $withImages): void
    {
        $texts = DemoTexts::products();
        $created = [];
        foreach (DemoCatalog::products() as $key => $spec) {
            $existing = $this->findProduct($key);
            if (null !== $existing) {
                $this->products[$key] = $existing;
                $this->translateProduct($existing, $texts[$key]);
                if (isset($spec['options'])) {
                    $this->translateOptions($existing);
                }
                continue;
            }
            $product = $this->buildProduct($key, $spec, $texts[$key]);
            $this->em->persist($product);
            $this->products[$key] = $product;
            $created[$key] = $spec;
            $this->count('products');
            $this->count('variants', $product->getVariants()->count());
        }
        $this->em->flush();

        // опції — другим проходом: компоненти посилаються на варіанти інших демо-товарів
        foreach ($created as $key => $spec) {
            if (isset($spec['options'])) {
                $this->addOptions($this->products[$key], $spec['options']);
            }
        }
        $this->em->flush();

        if ($withImages) {
            // і для вже наявних товарів: плейсхолдери міняються на фото з паку (DemoPhotos), фото без змін лишаються
            foreach (DemoCatalog::products() as $key => $spec) {
                $this->syncImages($this->products[$key], $key, $spec, $texts[$key]['name']);
                $this->em->flush();
            }
        }
    }

    private function findProduct(string $key): ?Product
    {
        $sku = DemoCatalog::SKU_PREFIX.$key;
        /** @var ProductVariant|null $variant */
        $variant = $this->em->createQueryBuilder()->select('v')->from(ProductVariant::class, 'v')
            ->where('v.sku = :sku OR v.sku LIKE :prefix')->setParameter('sku', $sku)->setParameter('prefix', addcslashes($sku, '%_').'-%')
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();

        return $variant?->getProduct();
    }

    /**
     * @param ProductSpec                                                                                          $spec
     * @param array{name: array<string, string>, short: array<string, string>, description: array<string, string>} $text
     */
    private function buildProduct(string $key, array $spec, array $text): Product
    {
        $sku = DemoCatalog::SKU_PREFIX.$key;
        $variants = $spec['variants'] ?? [['axes' => []]];
        $product = new Product(self::variantSku($sku, $variants[0]['axes'], \count($variants)), $this->currency, ProductKind::from($spec['kind'] ?? 'goods'));
        $product->setPriceMode(PriceMode::from($spec['mode'] ?? 'fixed'));
        if (isset($spec['brand'])) {
            $product->setBrand($this->brands[$spec['brand']]);
        }
        foreach ($spec['categories'] as $categoryKey) {
            $product->addCategory($this->categories[$categoryKey]);
        }
        $this->translateProduct($product, $text);

        // характеристики картки; значення осі для товару без варіантів — вісь його єдиного варіанта (фільтр бере осі з варіантів)
        $cardAxes = [];
        foreach ($spec['attributes'] ?? [] as $attributeKey => $value) {
            $attribute = $this->attributes[$attributeKey];
            if ($attribute->isVariantAxis()) {
                $cardAxes[$attributeKey] = (string) $value;
                continue;
            }
            if (AttributeType::Boolean === $attribute->getType()) {
                $product->setAttributeBoolean($attribute, (bool) $value);
            } elseif (AttributeType::Number === $attribute->getType()) {
                $product->setAttributeNumber($attribute, (string) $value);
            } else {
                $product->addAttributeOption($this->attributeValue($attributeKey, (string) $value));
            }
        }

        foreach ($variants as $i => $variantSpec) {
            $variant = 0 === $i ? $product->getDefaultVariant() : $product->addVariant(self::variantSku($sku, $variantSpec['axes'], \count($variants)));
            foreach ($variantSpec['axes'] + $cardAxes as $axisKey => $valueCode) {
                $variant->setAxisValue($this->attributeValue($axisKey, $valueCode));
            }
            $price = $variantSpec['price'] ?? $spec['price'];
            $old = $variantSpec['old'] ?? (isset($variantSpec['price']) ? null : ($spec['old'] ?? null));
            $variant->setPrice(self::uah($price, $this->currency), null === $old ? null : self::uah($old, $this->currency));
            $this->applyStock($variant, $variantSpec['stock'] ?? $spec['stock']);
            if (isset($spec['unit'])) {
                $variant->setUnit(SalesUnit::from($spec['unit']));
            }
            if (isset($spec['min']) || isset($spec['step'])) {
                $variant->setQuantityRules($spec['min'] ?? '1', $spec['step'] ?? '1');
            }
            foreach ($spec['tiers'] ?? [] as $tier) {
                // пороги — від ціни варіанта: та сама різниця, що й у базовій ціні товару
                $tierPrice = $tier['price'] + ($price - $spec['price']);
                $variant->addPriceTier($tier['min'], self::uah($tierPrice, $this->currency), ($tier['group'] ?? false) ? $this->wholesale : null);
            }
        }

        return $product;
    }

    /** @param array{name: array<string, string>, short: array<string, string>, description: array<string, string>} $text */
    private function translateProduct(Product $product, array $text): void
    {
        foreach ($this->locales->all() as $locale) {
            if (!$this->locales->isStale($product->getTranslation($locale)?->getName(), $text['name'], $locale)) {
                continue;
            }
            $slug = $this->locales->slug($text['name'], $locale);
            $translation = $product->translate($locale);
            $translation->setName($this->locales->text($text['name'], $locale))
                ->setSlug($translation->getSlug() === $slug ? $slug : $this->uniqueProductSlug($slug, $locale))
                ->setShortDescription($this->locales->text($text['short'], $locale))
                ->setDescription($this->locales->paragraph($text['description'], $locale));
            $this->countRefresh($product->getId());
        }
    }

    private function applyStock(ProductVariant $variant, string $stock): void
    {
        [$kind, $arg] = explode(':', $stock.':');
        $policy = match ($kind) {
            'in', 'out' => StockPolicy::InStock,
            'order' => StockPolicy::MadeToOrder,
            'free' => StockPolicy::NotTracked,
            default => throw new RuntimeException(\sprintf('Невідомий stock у DemoCatalog: %s', $stock)),
        };
        $variant->setStockPolicy($policy);
        if (StockPolicy::InStock === $policy) {
            $variant->setStock('in' === $kind ? $arg : '0');
        }
        if (StockPolicy::MadeToOrder === $policy) {
            $variant->setLeadTimeDays((int) $arg);
        }
    }

    private function uniqueProductSlug(string $slug, string $locale): string
    {
        $candidate = $slug;
        for ($n = 2; $n < 50; ++$n) {
            $taken = $this->em->createQueryBuilder()->select('COUNT(t.product)')->from(ProductTranslation::class, 't')
                ->where('t.locale = :locale AND t.slug = :slug')->setParameter('locale', $locale)->setParameter('slug', $candidate)
                ->getQuery()->getSingleScalarResult();
            if (0 === (int) $taken) {
                return $candidate;
            }
            $candidate = $slug.'-demo'.(2 === $n ? '' : '-'.$n);
        }

        return $slug.'-'.bin2hex(random_bytes(3));
    }

    private function attributeValue(string $attributeKey, string $code): AttributeValue
    {
        return $this->attributes[$attributeKey]->findValue($code)
            ?? throw new RuntimeException(\sprintf('У DemoCatalog нема значення %s.%s', $attributeKey, $code));
    }

    /** @param array<string, string> $axes */
    private static function variantSku(string $base, array $axes, int $variants): string
    {
        if (1 === $variants || [] === $axes) {
            return $base;
        }

        return $base.'-'.strtoupper(implode('-', array_values($axes)));
    }

    private static function uah(int $hryvnias, string $currency): Money
    {
        return Money::of($hryvnias * 100, $currency);
    }

    /** @return array<string, array<string, string>> код групи чи значення опції → мова → назва */
    private static function optionTexts(): array
    {
        return [
            'frame' => ['uk' => 'Дверна коробка', 'ru' => 'Дверная коробка', 'en' => 'Door frame'],
            'box_oak' => ['uk' => 'Коробка 2000 мм, дуб', 'ru' => 'Коробка 2000 мм, дуб', 'en' => 'Frame 2000 mm, oak'],
            'casing' => ['uk' => 'Лиштва', 'ru' => 'Наличник', 'en' => 'Architrave'],
            'one_side' => ['uk' => 'З одного боку (2,5 планки)', 'ru' => 'С одной стороны (2,5 планки)', 'en' => 'One side (2.5 strips)'],
            'both_sides' => ['uk' => 'З двох боків (5 планок)', 'ru' => 'С двух сторон (5 планок)', 'en' => 'Both sides (5 strips)'],
            'hardware' => ['uk' => 'Фурнітура', 'ru' => 'Фурнитура', 'en' => 'Hardware'],
            'handle_lira' => ['uk' => 'Ручка Ліра, чорна', 'ru' => 'Ручка Лира, черная', 'en' => 'Lira handle, black'],
            'handle_orbita' => ['uk' => 'Ручка Орбіта, латунь', 'ru' => 'Ручка Орбита, латунь', 'en' => 'Orbita handle, brass'],
            'lock_magnetic' => ['uk' => 'Замок магнітний', 'ru' => 'Замок магнитный', 'en' => 'Magnetic lock'],
            'hinges_3' => ['uk' => 'Петлі врізні, 3 шт', 'ru' => 'Петли врезные, 3 шт', 'en' => 'Mortise hinges, 3 pcs'],
            'extras' => ['uk' => 'Додатково', 'ru' => 'Дополнительно', 'en' => 'Extras'],
            'hidden_hinges' => ['uk' => 'Приховані петлі', 'ru' => 'Скрытые петли', 'en' => 'Concealed hinges'],
            'custom_size' => ['uk' => 'Нестандартний розмір', 'ru' => 'Нестандартный размер', 'en' => 'Custom size'],
        ];
    }

    /** Групи опцій: фурнітура компонентами, націнки сумою й відсотком; для набору — обов'язкові коробка й лиштва. Назви — translateOptions(). */
    private function addOptions(Product $product, string $preset): void
    {
        if ('door_kit' === $preset) {
            $frame = $product->addOptionGroup('frame', OptionSelection::Single, true);
            $frame->markDefault($frame->addComponent('box_oak', $this->products['FT-005']->getDefaultVariant(), '1'));

            $casing = $product->addOptionGroup('casing', OptionSelection::Single, true);
            $oneSide = $casing->addComponent('one_side', $this->products['FT-006']->getDefaultVariant(), '2.5');
            $casing->addComponent('both_sides', $this->products['FT-006']->getDefaultVariant(), '5');
            $casing->markDefault($oneSide);
        }

        $hardware = $product->addOptionGroup('hardware', OptionSelection::Multiple);
        $hardware->addComponent('handle_lira', $this->products['FT-001']->getDefaultVariant(), '1');
        $hardware->addComponent('handle_orbita', $this->products['FT-002']->getDefaultVariant(), '1');
        $hardware->addComponent('lock_magnetic', $this->products['FT-004']->getDefaultVariant(), '1');
        $hardware->addComponent('hinges_3', $this->products['FT-003']->getDefaultVariant(), '3', self::uah(480, $this->currency));

        $extras = $product->addOptionGroup('extras', OptionSelection::Multiple);
        $extras->addAbsoluteModifier('hidden_hinges', self::uah(450, $this->currency));
        $extras->addPercentModifier('custom_size', 1500);

        $this->translateOptions($product);
    }

    /** Назви груп і значень опцій за кодом (optionTexts); групи й значення, додані в адмінці, не чіпаються. */
    private function translateOptions(Product $product): void
    {
        $texts = self::optionTexts();
        foreach ($product->getOptionGroups() as $group) {
            foreach ($this->locales->all() as $locale) {
                $text = $texts[$group->getCode()] ?? null;
                if (null !== $text && $this->locales->isStale($group->getTranslation($locale)?->getName(), $text, $locale)) {
                    $group->translate($locale)->setName($this->locales->text($text, $locale));
                    $this->countRefresh($group->getId());
                }
            }
            foreach ($group->getValues() as $value) {
                foreach ($this->locales->all() as $locale) {
                    $text = $texts[$value->getCode()] ?? null;
                    if (null !== $text && $this->locales->isStale($value->getTranslation($locale)?->getLabel(), $text, $locale)) {
                        $value->translate($locale)->setLabel($this->locales->text($text, $locale));
                        $this->countRefresh($value->getId());
                    }
                }
            }
        }
    }

    /**
     * 1–3 зображення: слот 1 — спільне для картки, далі — по одному на колір (прив'язане до першого варіанта цього кольору),
     * решта — спільні. На кожен слот — фото з паку (DemoPhotos, <KEY>-<n>) або плейсхолдер. Повторний запуск: слот з тим самим
     * фото (contentHash) не чіпається; плейсхолдер (без contentHash) чи інше фото замінюється, старий файл видаляється
     * (Vich + кеш мініатюр); слоти без фото й без файлу лишаються плейсхолдерами. Дублів медіа немає.
     *
     * @param ProductSpec           $spec
     * @param array<string, string> $name
     */
    private function syncImages(Product $product, string $key, array $spec, array $name): void
    {
        $root = $this->rootKey($spec['categories'][0]);
        $background = DemoCatalog::palette()[$root] ?? '#777777';
        $title = $name[$this->locales->default()] ?? (string) reset($name);
        $fileBase = 'demo-'.strtolower($key);

        $colorVariants = [];
        foreach ($product->getVariants() as $variant) {
            $color = $variant->getAxisValue($this->attributes['color'])?->getCode();
            if (null !== $color && !isset($colorVariants[$color]) && $product->getVariants()->count() > 1) {
                $colorVariants[$color] = $variant;
            }
        }
        // план слотів: [варіант|null, підпис, колір фону, формат плейсхолдера]
        $slots = [[null, DemoCatalog::SKU_PREFIX.$key, $background, 'webp']];
        foreach ($colorVariants as $color => $variant) {
            if (\count($slots) >= $spec['images']) {
                break;
            }
            $slots[] = [$variant, $variant->getSku(), DemoCatalog::swatches()[$color] ?? $background, 'webp'];
        }
        while (\count($slots) < $spec['images']) {
            // друге зображення без кольорових варіантів — JPEG, щоб у вітрині були обидва формати
            $slots[] = [null, DemoCatalog::SKU_PREFIX.$key, $background, 'jpg'];
        }

        $links = $product->getMedia()->toArray();
        usort($links, static fn (ProductMedia $a, ProductMedia $b): int => $a->getPosition() <=> $b->getPosition());
        foreach ($slots as $i => [$variant, $caption, $color, $format]) {
            $n = $i + 1;
            $photo = $this->photos->product($key, $n);
            $link = $links[$i] ?? null;
            $current = $link?->getMedia();
            if (null !== $current && (null === $photo || !$this->isDemoMedia($current) || $current->getContentHash() === DemoPhotos::hash($photo))) {
                continue; // фото нема, слот уже не демо (замінив власник) або вже це фото — лишаємо як є
            }
            $media = null === $photo ? null : $this->photoMedia($photo, $title, $fileBase.'-'.$n);
            if (null !== $current) {
                if (null === $media) {
                    continue; // битий файл паку — лишаємо що є
                }
                $product->removeMedia($current);
                $this->em->flush(); // зв'язок (RESTRICT на media_id) — до видалення файлу
                $this->dropMedia($current);
                $this->count('media_replaced');
            } else {
                $this->count('media');
            }
            $media ??= $this->images->create($title, $caption, $color, $fileBase.'-'.$n, $format);
            $this->em->persist($media);
            $product->addMedia($media, $variant)->setPosition($i);
        }
    }

    /** Зображення категорії (плитка на головній): лише з фото паку; плейсхолдерів для категорій нема. */
    private function syncCategoryImages(): void
    {
        $texts = DemoTexts::categories();
        foreach ($this->categories as $key => $category) {
            $photo = $this->photos->category($key);
            if (null === $photo) {
                continue;
            }
            $current = $category->getImage();
            if (null !== $current && $current->getContentHash() === DemoPhotos::hash($photo)) {
                continue;
            }
            if (null !== $current && !$this->isDemoMedia($current)) {
                continue; // зображення задав власник — не чіпаємо
            }
            $title = $texts[$key]['name'][$this->locales->default()] ?? (string) reset($texts[$key]['name']);
            $media = $this->photoMedia($photo, $title, 'demo-category-'.$key);
            if (null === $media) {
                continue;
            }
            $this->em->persist($media);
            $category->setImage($media);
            $this->em->flush();
            if (null !== $current) {
                $this->dropMedia($current);
            }
            $this->count('media');
        }
    }

    /** Медіа, яке створив сідер (файл demo-…); усе інше — від власника чи адмінки, сідер його не знімає й не видаляє. */
    private function isDemoMedia(Media $media): bool
    {
        return str_starts_with($media->getPath(), DemoCatalog::SLUG_PREFIX);
    }

    /** Media з фото паку; битий файл (не JPEG/PNG/WebP) — попередження в лог і null, щоб seed не обривався посередині. */
    private function photoMedia(string $photo, string $alt, string $fileBase): ?Media
    {
        try {
            return $this->images->fromFile($photo, $alt, $fileBase);
        } catch (RuntimeException $e) {
            ($this->log)('  ! фото пропущено: '.$e->getMessage());
            $this->count('photo_skipped');

            return null;
        }
    }

    /** Файл медіатеки геть: мініатюри Liip і сам Media (Vich видаляє файл у public/media). */
    private function dropMedia(Media $media): void
    {
        $this->imagineCache->remove(['media/'.$media->getPath(), '/media/'.$media->getPath()]);
        $this->em->remove($media);
        $this->em->flush();
    }

    private function rootKey(string $categoryKey): string
    {
        $all = DemoCatalog::categories();
        while (null !== ($all[$categoryKey]['parent'] ?? null)) {
            $categoryKey = (string) $all[$categoryKey]['parent'];
        }

        return $categoryKey;
    }

    private function seedPromotions(): void
    {
        foreach (DemoCatalog::promotions() as $name => $spec) {
            $name = DemoCatalog::PROMOTION_PREFIX.$name;
            if (null !== $this->em->getRepository(Promotion::class)->findOneBy(['name' => $name])) {
                continue;
            }
            $promotion = new Promotion($name, $this->currency);
            if ('amount' === $spec['type']) {
                $promotion->setAmountDiscount($spec['value']);
            } else {
                $promotion->setPercentDiscount($spec['value']);
            }
            $promotion->setActive($spec['active'] ?? true);
            $promotion->setWholeCatalog($spec['whole'] ?? false);
            foreach ($spec['categories'] ?? [] as $key) {
                $promotion->addCategory($this->categories[$key]);
            }
            foreach ($spec['brands'] ?? [] as $key) {
                $promotion->addBrand($this->brands[$key]);
            }
            $this->em->persist($promotion);
            $this->count('promotions');
        }
    }

    // --- контент ---

    private function seedPages(): void
    {
        // у шапці — лише ці три; у футері — усі, у порядку DemoTexts::pages()
        $header = ['about', 'delivery', 'contacts'];
        $position = 0;
        foreach (DemoTexts::pages() as $key => $text) {
            $order = $position++;
            $slug = $this->locales->slug($text['title'], $this->locales->default(), DemoCatalog::SLUG_PREFIX);
            $existing = $this->em->getRepository(PageTranslation::class)->findOneBy(['locale' => $this->locales->default(), 'slug' => $slug]);
            if (null !== $existing) {
                // сторінка з попереднього сіду: порядок, щоб нові сторінки стали між наявними, і переклади-заглушки
                $existing->getPage()->setPosition($order);
                $this->translatePage($existing->getPage(), $text);
                continue;
            }
            $page = new Page();
            $page->setPublished(true);
            $page->setPosition($order);
            $page->setShowInHeader(\in_array($key, $header, true));
            $page->setShowInFooter(true);
            $this->translatePage($page, $text);
            $this->em->persist($page);
            $this->count('pages');
        }
    }

    /** @param array{title: array<string, string>, body: array<string, string>} $text */
    private function translatePage(Page $page, array $text): void
    {
        foreach ($this->locales->all() as $locale) {
            if (!$this->locales->isStale($page->getTranslation($locale)?->getTitle(), $text['title'], $locale)) {
                continue;
            }
            $page->translate($locale)
                ->setTitle($this->locales->text($text['title'], $locale))
                ->setSlug($this->locales->slug($text['title'], $locale, DemoCatalog::SLUG_PREFIX))
                ->setBody($this->locales->html($text['body'], $locale));
            $this->countRefresh($page->getId());
        }
    }

    /** Одне демо-рішення з демо-стелажів (DemoTexts::solution); є з попереднього сіду або стелажів нема — пропускається. */
    private function seedSolution(): void
    {
        $text = DemoTexts::solution();
        $slug = $this->locales->slug($text['slug'], $this->locales->default(), DemoCatalog::SLUG_PREFIX);
        if (null !== $this->em->getRepository(SolutionTranslation::class)->findOneBy(['locale' => $this->locales->default(), 'slug' => $slug])) {
            return;
        }
        $solution = new Solution();
        $solution->setPublished(true);
        foreach ($this->locales->all() as $locale) {
            $translation = $solution->translate($locale);
            $translation->setTitle($this->locales->text($text['title'], $locale))
                ->setSlug($this->locales->slug($text['slug'], $locale, DemoCatalog::SLUG_PREFIX))
                ->setIntro($this->locales->text($text['intro'], $locale))
                ->setTask($this->locales->text($text['task'], $locale))
                ->setLoads($this->locales->lines($text['loads'], $locale));
        }
        $position = 0;
        foreach ($text['options'] as $key => $option) {
            $product = $this->products[$key] ?? null;
            if (null === $product) {
                continue;
            }
            $row = new SolutionOption(null, $product);
            $row->setPosition($position++);
            foreach ($this->locales->all() as $locale) {
                $translation = $row->translate($locale);
                $translation->setPros($this->locales->lines($option['pros'], $locale))->setNote($this->locales->text($option['note'], $locale));
            }
            $solution->addOption($row);
        }
        if ([] === $solution->getOptions()->toArray()) {
            return;
        }
        $this->em->persist($solution);
        $this->count('solutions');
    }

    private function seedBlog(bool $withImages): void
    {
        $rubrics = [];
        foreach (DemoTexts::postCategories() as $key => $text) {
            $code = DemoCatalog::CODE_PREFIX.$key;
            $rubric = $this->em->getRepository(PostCategory::class)->findOneBy(['code' => $code]);
            if (null === $rubric) {
                $rubric = new PostCategory($code);
                $this->em->persist($rubric);
                $this->count('post_categories');
            }
            foreach ($this->locales->all() as $locale) {
                if ($this->locales->isStale($rubric->getTranslation($locale)?->getName(), $text['name'], $locale)) {
                    $rubric->translate($locale)
                        ->setName($this->locales->text($text['name'], $locale))
                        ->setSlug($this->locales->slug($text['name'], $locale, DemoCatalog::SLUG_PREFIX));
                    $this->countRefresh($rubric->getId());
                }
            }
            $rubrics[$key] = $rubric;
        }

        // дні відносно запуску: три опубліковані, один — з майбутньою датою (на сайті не видно до настання дати)
        $plan = ['post1' => ['advice', -20], 'post2' => ['advice', -9], 'post3' => ['news', -3], 'post4' => ['news', 30]];
        foreach (DemoTexts::posts() as $key => $text) {
            $slug = $this->locales->slug($text['title'], $this->locales->default(), DemoCatalog::SLUG_PREFIX);
            $existing = $this->em->getRepository(PostTranslation::class)->findOneBy(['locale' => $this->locales->default(), 'slug' => $slug]);
            if (null !== $existing) {
                $this->translatePost($existing->getPost(), $text);
                if (self::LEGACY_AUTHOR === $existing->getPost()->getAuthorName()) {
                    $existing->getPost()->setAuthorName(null); // підпис одною мовою світився б українською на /en/
                }
                if ($withImages) {
                    $this->syncCover($existing->getPost(), $key, $text['title']);
                }
                continue;
            }
            [$rubric, $days] = $plan[$key] ?? ['news', -1];
            $post = new Post();
            $post->setCategory($rubrics[$rubric] ?? null);
            $post->setPublishedAt(new DateTimeImmutable(\sprintf('%+d days 10:00', $days)));
            $this->translatePost($post, $text);
            if ($withImages) {
                $this->syncCover($post, $key, $text['title']);
            }
            $this->em->persist($post);
            $this->count('posts');
        }
    }

    /** @param array{title: array<string, string>, excerpt: array<string, string>, body: array<string, string>} $text */
    private function translatePost(Post $post, array $text): void
    {
        foreach ($this->locales->all() as $locale) {
            if (!$this->locales->isStale($post->getTranslation($locale)?->getTitle(), $text['title'], $locale)) {
                continue;
            }
            $post->translate($locale)
                ->setTitle($this->locales->text($text['title'], $locale))
                ->setSlug($this->locales->slug($text['title'], $locale, DemoCatalog::SLUG_PREFIX))
                ->setExcerpt($this->locales->text($text['excerpt'], $locale))
                ->setBody($this->locales->html($text['body'], $locale));
            $this->countRefresh($post->getId());
        }
    }

    /**
     * Обкладинка статті: фото паку (post-<key>) або плейсхолдер; як syncImages — те саме фото не чіпається, плейсхолдер замінюється.
     *
     * @param array<string, string> $titles
     */
    private function syncCover(Post $post, string $key, array $titles): void
    {
        $photo = $this->photos->post($key);
        $current = $post->getCover();
        if (null !== $current && (null === $photo || !$this->isDemoMedia($current) || $current->getContentHash() === DemoPhotos::hash($photo))) {
            return; // фото нема, обкладинку задав власник або вже це фото
        }
        $title = $titles[$this->locales->default()] ?? (string) reset($titles);
        $cover = null === $photo ? null : $this->photoMedia($photo, $title, 'demo-post-'.$key);
        if (null === $cover && null !== $current) {
            return; // битий файл паку — лишаємо що є
        }
        $cover ??= $this->images->create($title, 'Blog', '#5b6b5a', 'demo-post-'.$key, 'jpg');
        $this->em->persist($cover);
        $post->setCover($cover);
        if (null !== $current) {
            $this->em->flush();
            $this->dropMedia($current);
            $this->count('media_replaced');
        } else {
            $this->count('media');
        }
    }

    /** Лише порожні значення: налаштування, які власник уже задав, демо не перезаписує. */
    private function seedSettings(): void
    {
        if ('' === trim((string) $this->settings->get(SettingKeys::ORDERS_NOTIFY_EMAIL))) {
            $this->settings->set(SettingKeys::ORDERS_NOTIFY_EMAIL, 'demo-orders@example.com');
            $this->count('settings');
        }
        if ($this->settings->getBool(SettingKeys::TAGS_ON_DEV)) {
            $this->settings->set(SettingKeys::TAGS_ON_DEV, '0');
            $this->count('settings');
        }
    }

    /** @return list<string> задані id аналітики (демо їх не стирає — лише попереджає) */
    public function analyticsIds(): array
    {
        $set = [];
        foreach ([SettingKeys::GTM_CONTAINER_ID, SettingKeys::GA4_MEASUREMENT_ID, SettingKeys::FB_PIXEL_ID] as $code) {
            if (null !== SettingKeys::validValue($code, $this->settings->get($code))) {
                $set[] = $code;
            }
        }

        return $set;
    }

    // --- замовлення ---

    /** Гостьові замовлення за планом DemoOrderPlans::guests(): штатний PlaceOrder без CRM і листів, дати по ~180 днях. */
    private function seedOrders(): void
    {
        foreach (DemoOrderPlans::guests() as $n => $plan) {
            if ($this->orders->exists($n)) {
                continue;
            }
            if (null !== $this->orders->place($n, $plan)) {
                $this->count('orders');
            }
        }
    }
}
