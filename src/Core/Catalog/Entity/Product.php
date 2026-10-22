<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Entity;

use App\Core\Catalog\Enum\AttributeType;
use App\Core\Catalog\Enum\OptionSelection;
use App\Core\Catalog\Enum\PriceMode;
use App\Core\Catalog\Enum\ProductKind;
use App\Core\Catalog\Enum\StockPolicy;
use App\Core\Catalog\Exception\CatalogInvariantViolation;
use App\Core\Shared\Text\MachineCode;
use App\Core\Shared\Translation\AbstractTranslation;
use App\Core\Shared\Translation\TranslatableInterface;
use App\Core\Shared\Translation\TranslatableTrait;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Картка товару (ADR Rev 2, D2 Alt A): переклади, категорії, бренд, характеристики, медіа, групи опцій.
 * Купується не картка, а ProductVariant; у кожного товару завжди ≥1 варіант (для простого — один).
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_product')]
#[ORM\Index(name: 'idx_catalog_product_kind_active', columns: ['kind', 'active'])]
#[ORM\Index(name: 'idx_catalog_product_featured', columns: ['featured'])]
#[ORM\HasLifecycleCallbacks]
class Product implements TranslatableInterface
{
    /** @use TranslatableTrait<ProductTranslation> */
    use TranslatableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: ProductKind::class)]
    private ProductKind $kind;

    #[ORM\Column(length: 16, enumType: PriceMode::class)]
    private PriceMode $priceMode = PriceMode::Fixed;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Brand $brand = null;

    /** @var Collection<int, Category> */
    #[ORM\ManyToMany(targetEntity: Category::class)]
    #[ORM\JoinTable(name: 'catalog_product_category')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $categories;

    /** Головна категорія (хлібні крихти, canonical) — завжди одна з $categories. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $mainCategory = null;

    #[ORM\Column]
    private bool $active = true;

    /** «Показувати на головній»: такі товари йдуть першими у вітрині головної (HomeShowcase). */
    #[ORM\Column(options: ['default' => false])]
    private bool $featured = false;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /** Коли джерело імпорту (фід/CRM) востаннє змінювало товар (ADR D2). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $sourceUpdatedAt = null;

    /** sha1 контенту останнього імпортованого рядка (без цін/залишків): збіг → імпорт не чіпає граф товару. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $sourceHash = null;

    /** @var Collection<int, ProductVariant> */
    #[Assert\Valid]
    #[Assert\Count(min: 1, minMessage: 'catalog.product.variants.min_count')]
    #[ORM\OneToMany(targetEntity: ProductVariant::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $variants;

    /** @var Collection<int, ProductAttributeValue> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: ProductAttributeValue::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true)]
    private Collection $attributeValues;

    /** @var Collection<int, OptionGroup> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: OptionGroup::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $optionGroups;

    /** @var Collection<int, ProductMedia> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: ProductMedia::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $media;

    /** @var Collection<string, ProductTranslation> */
    #[Assert\Valid]
    #[ORM\OneToMany(targetEntity: ProductTranslation::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    /** Товар одразу отримує перший варіант: без нього картку не можна покласти в кошик. */
    public function __construct(string $sku, string $currency, ProductKind $kind = ProductKind::Goods)
    {
        $this->kind = $kind;
        $this->categories = new ArrayCollection();
        $this->variants = new ArrayCollection();
        $this->attributeValues = new ArrayCollection();
        $this->optionGroups = new ArrayCollection();
        $this->media = new ArrayCollection();
        $this->translations = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new DateTimeImmutable();

        $variant = new ProductVariant($this, $sku, $currency);
        $this->variants->add($variant);
        if (ProductKind::Service === $kind) {
            $variant->setStockPolicy(StockPolicy::NotTracked);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        $name = $this->displayText(static fn (ProductTranslation $t): string => $t->getName());
        if (null !== $name) {
            return $name;
        }

        return $this->variants->isEmpty() ? \sprintf('#%d', $this->id ?? 0) : $this->getDefaultVariant()->getSku();
    }

    public function getKind(): ProductKind
    {
        return $this->kind;
    }

    /** Послуга не має залишку: її варіанти переводяться в NotTracked (ADR Rev 5). */
    public function setKind(ProductKind $kind): void
    {
        $this->kind = $kind;
        if (ProductKind::Service === $kind) {
            foreach ($this->variants as $variant) {
                $variant->setStockPolicy(StockPolicy::NotTracked);
            }
        }
    }

    public function getPriceMode(): PriceMode
    {
        return $this->priceMode;
    }

    public function setPriceMode(PriceMode $priceMode): void
    {
        $this->priceMode = $priceMode;
    }

    public function getBrand(): ?Brand
    {
        return $this->brand;
    }

    public function setBrand(?Brand $brand): void
    {
        $this->brand = $brand;
    }

    // --- категорії ---

    public function addCategory(Category $category): void
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
        }
        $this->mainCategory ??= $category;
    }

    public function removeCategory(Category $category): void
    {
        $this->categories->removeElement($category);
        if ($this->mainCategory === $category) {
            $this->mainCategory = $this->categories->first() ?: null;
        }
    }

    /** @return Collection<int, Category> */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function getMainCategory(): ?Category
    {
        return $this->mainCategory;
    }

    public function setMainCategory(Category $category): void
    {
        if (!$this->categories->contains($category)) {
            throw new CatalogInvariantViolation('Головна категорія має бути серед категорій товару', 'error.product.main_category_not_assigned');
        }
        $this->mainCategory = $category;
    }

    // --- варіанти ---

    /** Новий варіант у валюті товару; залишкова політика — як у послуги, якщо товар — послуга. */
    public function addVariant(string $sku): ProductVariant
    {
        $variant = new ProductVariant($this, $sku, $this->getCurrency());
        if (ProductKind::Service === $this->kind) {
            $variant->setStockPolicy(StockPolicy::NotTracked);
        }
        $variant->setPosition($this->variants->count());
        $this->variants->add($variant);

        return $variant;
    }

    public function removeVariant(ProductVariant $variant): void
    {
        if (!$this->variants->contains($variant)) {
            return;
        }
        if (1 === $this->variants->count()) {
            throw new CatalogInvariantViolation('Товар не може лишитися без жодного варіанта', 'error.product.last_variant');
        }
        $this->variants->removeElement($variant);
        foreach ($this->media as $media) {
            if ($media->getVariant() === $variant) {
                $media->setVariant(null);
            }
        }
    }

    /** @return Collection<int, ProductVariant> */
    public function getVariants(): Collection
    {
        return $this->variants;
    }

    public function getDefaultVariant(): ProductVariant
    {
        $first = $this->variants->first();
        if (false === $first) {
            throw new CatalogInvariantViolation('Товар без варіантів', 'error.product.no_variants');
        }

        return $first;
    }

    public function findVariantBySku(string $sku): ?ProductVariant
    {
        return $this->variants->findFirst(static fn (int $_, ProductVariant $v): bool => $v->getSku() === trim($sku));
    }

    /** Усі варіанти товару в одній валюті: береться з першого. */
    public function getCurrency(): string
    {
        return $this->getDefaultVariant()->getCurrency();
    }

    // --- характеристики (EAV) ---

    /** select: замінює попереднє значення; multiselect: додає ще одне. */
    public function addAttributeOption(AttributeValue $option): void
    {
        $attribute = $option->getAttribute();
        if (!$attribute->hasOptions()) {
            throw new CatalogInvariantViolation(\sprintf('Атрибут "%s" не має варіантів значень', $attribute->getCode()), 'error.product.attribute_no_values', ['attribute' => $attribute->getCode()]);
        }
        $existing = $this->getAttributeValues($attribute);
        foreach ($existing as $value) {
            if ($value->getOption() === $option) {
                return;
            }
        }
        if (AttributeType::Select === $attribute->getType()) {
            $this->removeAttribute($attribute);
        }
        $this->attributeValues->add(ProductAttributeValue::option($this, $option));
    }

    /** Наявне значення міняється на місці (той самий рядок), а не видаляється й додається: unique (product, attribute, 0). */
    public function setAttributeNumber(Attribute $attribute, string $value): void
    {
        $this->assertType($attribute, AttributeType::Number);
        $existing = $this->getAttributeValues($attribute);
        if ([] !== $existing) {
            $existing[0]->changeNumber($value);

            return;
        }
        $this->attributeValues->add(ProductAttributeValue::number($this, $attribute, $value));
    }

    public function setAttributeBoolean(Attribute $attribute, bool $value): void
    {
        $this->assertType($attribute, AttributeType::Boolean);
        $existing = $this->getAttributeValues($attribute);
        if ([] !== $existing) {
            $existing[0]->changeBoolean($value);

            return;
        }
        $this->attributeValues->add(ProductAttributeValue::boolean($this, $attribute, $value));
    }

    public function removeAttribute(Attribute $attribute): void
    {
        foreach ($this->getAttributeValues($attribute) as $value) {
            $this->attributeValues->removeElement($value);
        }
    }

    public function removeAttributeOption(AttributeValue $option): void
    {
        foreach ($this->getAttributeValues($option->getAttribute()) as $value) {
            if ($value->getOption() === $option) {
                $this->attributeValues->removeElement($value);
            }
        }
    }

    /** @return list<ProductAttributeValue> усі значення або лише одного атрибута */
    public function getAttributeValues(?Attribute $attribute = null): array
    {
        $values = $this->attributeValues->toArray();
        if (null !== $attribute) {
            $values = array_filter($values, static fn (ProductAttributeValue $v): bool => $v->getAttribute() === $attribute);
        }

        return array_values($values);
    }

    // --- опції (ADR Rev 6) ---

    public function addOptionGroup(string $code, OptionSelection $selection = OptionSelection::Single, bool $required = false): OptionGroup
    {
        $code = MachineCode::assertValid($code);
        if (null !== $this->findOptionGroup($code)) {
            throw new CatalogInvariantViolation(\sprintf('Група опцій "%s" вже є в товарі', $code), 'error.option_group.exists', ['code' => $code]);
        }
        $group = new OptionGroup($this, $code, $selection, $required);
        $group->setPosition($this->optionGroups->count());
        $this->optionGroups->add($group);

        return $group;
    }

    public function removeOptionGroup(OptionGroup $group): void
    {
        $this->optionGroups->removeElement($group);
    }

    public function findOptionGroup(string $code): ?OptionGroup
    {
        return $this->optionGroups->findFirst(static fn (int $_, OptionGroup $g): bool => $g->getCode() === $code);
    }

    /** @return Collection<int, OptionGroup> */
    public function getOptionGroups(): Collection
    {
        return $this->optionGroups;
    }

    // --- медіа ---

    /** Прив'язка файлу до картки; з $variant — фото конкретного варіанта (колір). */
    public function addMedia(Media $media, ?ProductVariant $variant = null): ProductMedia
    {
        foreach ($this->media as $existing) {
            if ($existing->getMedia() === $media) {
                throw new CatalogInvariantViolation('Цей файл уже прив\'язано до товару', 'error.product.media_already_attached');
            }
        }
        $link = new ProductMedia($this, $media);
        $link->setVariant($variant);
        $link->setPosition($this->media->count());
        $this->media->add($link);

        return $link;
    }

    public function removeMedia(Media $media): void
    {
        foreach ($this->media as $link) {
            if ($link->getMedia() === $media) {
                $this->media->removeElement($link);
            }
        }
    }

    /** @return Collection<int, ProductMedia> */
    public function getMedia(): Collection
    {
        return $this->media;
    }

    // --- службове ---

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setFeatured(bool $featured): void
    {
        $this->featured = $featured;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getSourceUpdatedAt(): ?DateTimeImmutable
    {
        return $this->sourceUpdatedAt;
    }

    public function setSourceUpdatedAt(?DateTimeImmutable $sourceUpdatedAt): void
    {
        $this->sourceUpdatedAt = $sourceUpdatedAt;
    }

    public function getSourceHash(): ?string
    {
        return $this->sourceHash;
    }

    public function setSourceHash(?string $sourceHash): void
    {
        $this->sourceHash = $sourceHash;
    }

    protected function createTranslation(string $locale): AbstractTranslation
    {
        return new ProductTranslation($this, $locale);
    }

    private function assertType(Attribute $attribute, AttributeType $expected): void
    {
        if ($attribute->getType() !== $expected) {
            throw new CatalogInvariantViolation(\sprintf('Атрибут "%s" має тип %s, а не %s', $attribute->getCode(), $attribute->getType()->value, $expected->value), 'error.product.attribute_type', ['attribute' => $attribute->getCode(), 'type' => $attribute->getType()->value, 'expected' => $expected->value]);
        }
    }
}
