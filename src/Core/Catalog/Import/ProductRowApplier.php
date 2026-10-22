<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Import;

use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\AttributeValue;
use App\Core\Catalog\Entity\Brand;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\CategoryTranslation;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Enum\AttributeType;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Catalog\Url\CatalogSlugs;
use App\Core\Integration\Entity\ExternalRef;
use App\Core\Shared\Html\ContentHtml;
use App\Core\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * ORM-шлях імпорту: рядок → граф Product (переклади, бренд, категорії за шляхом, характеристики, варіанти з осями).
 * Викликається лише для нових або змінених (contentHash) карток; ціни/залишки існуючих варіантів уже оновив batch.
 * Довідники (бренд, категорія, характеристика, значення), яких нема, створюються — фід постачальника є їх джерелом.
 */
final class ProductRowApplier
{
    /** @var array<string, Category> шлях → категорія, кеш на час чанка */
    private array $categories = [];
    /** @var array<string, Attribute> */
    private array $attributes = [];
    /** @var array<string, Brand> */
    private array $brands = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        /** Машинні коди характеристик/значень і слаги брендів — ключі пошуку при повторному імпорті: лише глобальний slugger. */
        private readonly SluggerInterface $slugger,
        /** URL-слаги товарів і категорій (UkrainianSlugger); ключами пошуку не є. */
        #[Target('urlSlugger')] private readonly SluggerInterface $urlSlugger,
        private readonly CatalogSlugs $slugs,
        /** Описи з фіду — HTML постачальника: білий список, бо шаблон виводить їх |raw (консиліум безпеки 10.10 п. 2) */
        private readonly ContentHtml $html,
        #[Autowire('%app.default_locale%')] private readonly string $defaultLocale,
    ) {
    }

    public function resetCache(): void
    {
        $this->categories = $this->attributes = $this->brands = [];
        $this->slugs->reset();
    }

    /** @return array{product: Product, created: bool, warnings: list<string>} warnings — у звіт запуску, картку не відхиляють */
    public function apply(string $system, ProductImportRow $row, ?DateTimeImmutable $importedAt = null): array
    {
        $warnings = [];
        $product = $this->findProduct($system, $row);
        $created = null === $product;
        if (null === $product) {
            $first = $row->variants[0];
            $product = new Product($first->sku, $first->currency, $row->kind);
            $product->getDefaultVariant()->setExternalId($first->externalId);
            $this->em->persist($product);
        } else {
            $product->setKind($row->kind);
        }
        $product->setPriceMode($row->priceMode);
        $product->setActive($row->active);

        foreach ($row->names as $locale => $name) {
            if ('' !== trim($name)) {
                $product->translate($locale)->setName($name);
                $translation = $product->translate($locale);
                if (null === $translation->getSlug()) {
                    $translation->setSlug($this->slugs->uniqueProductSlug($this->urlSlug($name, $locale), $locale, $product->getId()));
                }
            }
        }
        foreach ($row->descriptions as $locale => $description) {
            if ($this->html->exceedsLimit($description)) {
                $warnings[] = \sprintf('Картка %s: опис (%s) довший за %d байт — обрізано санітайзером', $row->externalId, $locale, ContentHtml::MAX_INPUT_LENGTH);
            }
            $product->translate($locale)->setDescription($this->html->clean($description));
        }

        $product->setBrand(null !== $row->brand && '' !== trim($row->brand) ? $this->brand($row->brand) : null);

        foreach ($row->categoryPaths as $i => $path) {
            $translated = [];
            foreach ($row->categoryTranslations as $locale => $paths) {
                if (isset($paths[$i]) && \count($paths[$i]) === \count($path)) {
                    $translated[(string) $locale] = $paths[$i];
                }
            }
            $category = $this->categoryByPath($path, $translated);
            if (null !== $category) {
                $product->addCategory($category);
            }
        }

        foreach ($row->attributes as $code => $value) {
            $this->applyAttribute($product, (string) $code, $value);
        }

        foreach ($row->variants as $variantRow) {
            $this->applyVariant($product, $variantRow);
        }

        $product->setSourceUpdatedAt($row->sourceUpdatedAt ?? $importedAt ?? new DateTimeImmutable());
        $product->setSourceHash($row->contentHash());

        if ($created) {
            // ExternalRef потребує id товару: flush одразу, щоб ref став у тій самій транзакції
            $this->em->flush();
            $this->em->persist(new ExternalRef($system, ExternalRef::TYPE_PRODUCT, (int) $product->getId(), $row->externalId));
        }

        return ['product' => $product, 'created' => $created, 'warnings' => $warnings];
    }

    private function findProduct(string $system, ProductImportRow $row): ?Product
    {
        $ref = $this->em->getRepository(ExternalRef::class)->findOneBy(['system' => $system, 'entityType' => ExternalRef::TYPE_PRODUCT, 'externalId' => $row->externalId]);
        if (null !== $ref) {
            return $this->em->find(Product::class, $ref->getEntityId());
        }
        // картки ще нема в цьому джерелі: можливо, варіант уже є (інше джерело чи ручне створення) — приєднати за externalId/sku
        $variants = $this->em->getRepository(ProductVariant::class);
        foreach ($row->variants as $variantRow) {
            $variant = $variants->findOneBy(['externalId' => $variantRow->externalId]) ?? $variants->findOneBy(['sku' => $variantRow->sku]);
            if (null !== $variant) {
                $this->em->persist(new ExternalRef($system, ExternalRef::TYPE_PRODUCT, (int) $variant->getProduct()->getId(), $row->externalId));

                return $variant->getProduct();
            }
        }

        return null;
    }

    private function applyVariant(Product $product, VariantImportRow $row): void
    {
        $variant = null;
        foreach ($product->getVariants() as $existing) {
            if ($existing->getExternalId() === $row->externalId || $existing->getSku() === $row->sku) {
                $variant = $existing;
                break;
            }
        }
        if (null === $variant) {
            $variant = $product->addVariant($row->sku);
        } elseif ($variant->getSku() !== $row->sku) {
            $variant->changeSku($row->sku);
        }
        $variant->setExternalId($row->externalId);
        $variant->setBarcode($row->barcode);
        $variant->setPrice(Money::of($row->priceMinor, $row->currency), null === $row->oldPriceMinor ? null : Money::of($row->oldPriceMinor, $row->currency));
        $variant->setStock($row->stock);
        $variant->setUnit($row->unit);
        $variant->setActive($row->active);
        try {
            $variant->setStockPolicy($row->stockPolicy);
        } catch (CatalogInvariantViolation) {
            // послуга: політика лишається not_tracked
        }
        foreach ($row->axes as $attributeCode => $valueCode) {
            $attribute = $this->attribute((string) $attributeCode, AttributeType::Select, axis: true);
            $variant->setAxisValue($this->attributeValue($attribute, (string) $valueCode));
        }
    }

    /** @param string|list<string>|bool|null $value */
    private function applyAttribute(Product $product, string $code, string|array|bool|null $value): void
    {
        if (null === $value || '' === $value || [] === $value) {
            $attribute = $this->em->getRepository(Attribute::class)->findOneBy(['code' => $code]);
            if (null !== $attribute) {
                $product->removeAttribute($attribute);
            }

            return;
        }
        $type = match (true) {
            \is_bool($value) => AttributeType::Boolean,
            \is_array($value) => AttributeType::MultiSelect,
            is_numeric(str_replace(',', '.', $value)) => AttributeType::Number,
            default => AttributeType::Select,
        };
        $attribute = $this->attribute($code, $type);
        switch ($attribute->getType()) {
            case AttributeType::Boolean:
                $product->setAttributeBoolean($attribute, \is_bool($value) ? $value : \in_array(mb_strtolower((string) (\is_array($value) ? '' : $value)), ['1', 'true', 'так', 'yes', 'да'], true));
                break;
            case AttributeType::Number:
                $product->setAttributeNumber($attribute, (string) (\is_array($value) ? $value[0] : $value));
                break;
            case AttributeType::Select:
                $product->addAttributeOption($this->attributeValue($attribute, (string) (\is_array($value) ? $value[0] : $value)));
                break;
            case AttributeType::MultiSelect:
                // набір звіряється, а не «видалити все й додати»: Doctrine виконує INSERT раніше за DELETE, і те саме значення
                // при повторному імпорті зміненої картки падало на uniq_catalog_product_attribute_value (en-pharmacy 19.10.2026)
                $wanted = [];
                foreach ((array) $value as $one) {
                    $option = $this->attributeValue($attribute, (string) $one);
                    $wanted[spl_object_id($option)] = $option;
                }
                foreach ($product->getAttributeValues($attribute) as $existing) {
                    $option = $existing->getOption();
                    if (null !== $option && !isset($wanted[spl_object_id($option)])) {
                        $product->removeAttributeOption($option);
                    }
                }
                foreach ($wanted as $option) {
                    $product->addAttributeOption($option);
                }
                break;
        }
    }

    private function attribute(string $code, AttributeType $type, bool $axis = false): Attribute
    {
        $code = $this->machineCode($code);
        if (!isset($this->attributes[$code])) {
            $attribute = $this->em->getRepository(Attribute::class)->findOneBy(['code' => $code]);
            if (null === $attribute) {
                $attribute = new Attribute($code, $type);
                $attribute->translate($this->defaultLocale)->setName($code);
                if ($axis) {
                    $attribute->setVariantAxis(true);
                }
                $this->em->persist($attribute);
            }
            $this->attributes[$code] = $attribute;
        }

        return $this->attributes[$code];
    }

    /** Значення select за кодом або за людською назвою (код — транслітерація), створюється, якщо нема. */
    private function attributeValue(Attribute $attribute, string $raw): AttributeValue
    {
        $raw = trim($raw);
        $value = $attribute->findValue($raw) ?? $attribute->findValue($this->machineCode($raw));
        if (null === $value) {
            $value = $attribute->addValue($this->machineCode($raw));
            $value->translate($this->defaultLocale)->setValue($raw);
        }

        return $value;
    }

    private function brand(string $name): Brand
    {
        $name = trim($name);
        $slug = $this->slug($name);
        if (!isset($this->brands[$slug])) {
            $brand = $this->em->getRepository(Brand::class)->findOneBy(['slug' => $slug]);
            if (null === $brand) {
                $brand = new Brand($name, $slug);
                $this->em->persist($brand);
            }
            $this->brands[$slug] = $brand;
        }

        return $this->brands[$slug];
    }

    /**
     * @param list<string>                $path       назви мовою магазину за замовчуванням
     * @param array<string, list<string>> $translated мова → той самий шлях (сегмент за сегментом); задає назву/слаг цією мовою, якщо їх ще нема
     */
    private function categoryByPath(array $path, array $translated = []): ?Category
    {
        $path = array_values(array_filter(array_map(trim(...), $path), static fn (string $p): bool => '' !== $p));
        if ([] === $path) {
            return null;
        }
        $parent = null;
        $walked = [];
        foreach ($path as $depth => $name) {
            $walked[] = $name;
            $key = implode("\0", $walked);
            if (!isset($this->categories[$key])) {
                $found = $this->findChildCategory($parent, $name);
                if (null === $found) {
                    $found = new Category($parent);
                    $found->translate($this->defaultLocale)->setName($name)->setSlug($this->slugs->uniqueCategorySlug($this->urlSlug($name, $this->defaultLocale), $this->defaultLocale, self::pathOf($parent, $this->defaultLocale)));
                    $this->em->persist($found);
                }
                $this->categories[$key] = $found;
            }
            foreach ($translated as $locale => $names) {
                $label = trim($names[$depth] ?? '');
                $translation = $this->categories[$key]->getTranslation($locale);
                if ('' !== $label && $locale !== $this->defaultLocale && (!$translation instanceof CategoryTranslation || '' === $translation->getName())) {
                    $this->categories[$key]->translate($locale)->setName($label)->setSlug($this->slugs->uniqueCategorySlug($this->urlSlug($label, $locale), $locale, self::pathOf($parent, $locale), $this->categories[$key]->getId()));
                }
            }
            $parent = $this->categories[$key];
        }

        return $parent;
    }

    private function findChildCategory(?Category $parent, string $name): ?Category
    {
        if (null !== $parent && null === $parent->getId()) {
            return null; // батько ще не збережений — дітей у БД бути не може
        }
        $qb = $this->em->createQueryBuilder()->select('c')->from(Category::class, 'c')->join('c.translations', 't')
            ->where('t.locale = :locale')->andWhere('t.name = :name')->setParameter('locale', $this->defaultLocale)->setParameter('name', $name)->setMaxResults(1);
        if (null === $parent) {
            $qb->andWhere('c.parent IS NULL');
        } else {
            $qb->andWhere('c.parent = :parent')->setParameter('parent', $parent);
        }

        /** @var Category|null $category */
        $category = $qb->getQuery()->getOneOrNullResult();

        return $category;
    }

    /**
     * Шлях категорії цією мовою з ланцюжка слагів у пам'яті (нова категорія ще без CategoryTranslation.path); null —
     * корінь. Предок без слага — шлях теж null, але CatalogSlugs тоді перевіряє як корінь (обережніше).
     */
    private static function pathOf(?Category $category, string $locale): ?string
    {
        $slugs = [];
        for ($c = $category; null !== $c; $c = $c->getParent()) {
            $slug = $c->getTranslation($locale)?->getSlug();
            if (null === $slug) {
                return null;
            }
            array_unshift($slugs, $slug);
        }

        return [] === $slugs ? null : implode('/', $slugs);
    }

    /** Слаг бренду — ключ пошуку наявного бренду (findOneBy slug): та сама транслітерація, що й у вже імпортованих. */
    private function slug(string $text): string
    {
        return self::cut($this->slugger->slug($text, '-', $this->defaultLocale)->lower()->toString());
    }

    private function urlSlug(string $text, string $locale): string
    {
        return self::cut($this->urlSlugger->slug($text, '-', $locale)->lower()->toString());
    }

    private static function cut(string $slug): string
    {
        return '' === $slug ? 'item-'.bin2hex(random_bytes(3)) : mb_substr($slug, 0, 190);
    }

    private function machineCode(string $text): string
    {
        $code = strtolower(trim((string) preg_replace('/[^a-z0-9_]+/', '_', $this->slugger->slug($text, '_', $this->defaultLocale)->lower()->toString()), '_'));
        if ('' === $code || !preg_match('/^[a-z]/', $code)) {
            $code = 'v_'.$code;
        }

        return mb_substr($code, 0, 64);
    }
}
