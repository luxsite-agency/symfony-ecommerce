<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Resolve;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Changeset\Validate\TranslationFields;
use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\Post;
use App\Core\Customer\Entity\CustomerGroup;
use App\Core\Shared\Translation\TranslatableInterface;

/** Завантажені сутності пакета за id, SKU і кодом (ChangesetResolver). */
final class ResolvedTargets
{
    /** @var array<int, ProductVariant> */
    private array $variantsById = [];
    /** @var array<string, ProductVariant> */
    private array $variantsBySku = [];
    /** @var array<int, Product> */
    private array $products = [];
    /** @var array<string, Product> */
    private array $productsBySku = [];
    /** @var array<int, Category> */
    private array $categories = [];
    /** @var array<int, Page> */
    private array $pages = [];
    /** @var array<int, Post> */
    private array $posts = [];
    /** @var array<string, CustomerGroup> */
    private array $groups = [];

    public function addVariant(ProductVariant $variant): void
    {
        $this->variantsById[(int) $variant->getId()] = $variant;
        $this->variantsBySku[$variant->getSku()] = $variant;
    }

    public function addProduct(Product $product): void
    {
        $this->products[(int) $product->getId()] = $product;
    }

    public function addProductBySku(string $sku, Product $product): void
    {
        $this->productsBySku[$sku] = $product;
        $this->addProduct($product);
    }

    public function addCategory(Category $category): void
    {
        $this->categories[(int) $category->getId()] = $category;
    }

    public function addPage(Page $page): void
    {
        $this->pages[(int) $page->getId()] = $page;
    }

    public function addPost(Post $post): void
    {
        $this->posts[(int) $post->getId()] = $post;
    }

    public function addGroup(CustomerGroup $group): void
    {
        $this->groups[$group->getCode()] = $group;
    }

    public function variant(int|string $ref): ?ProductVariant
    {
        return \is_int($ref) ? ($this->variantsById[$ref] ?? null) : ($this->variantsBySku[$ref] ?? null);
    }

    public function product(int|string $ref): ?Product
    {
        return \is_int($ref) ? ($this->products[$ref] ?? null) : ($this->productsBySku[$ref] ?? null);
    }

    public function category(int $id): ?Category
    {
        return $this->categories[$id] ?? null;
    }

    public function group(string $code): ?CustomerGroup
    {
        return $this->groups[$code] ?? null;
    }

    /** Корінь перекладу за типом set_translation */
    public function translatable(string $type, int $id): ?TranslatableInterface
    {
        return match ($type) {
            TranslationFields::PRODUCT => $this->products[$id] ?? null,
            TranslationFields::CATEGORY => $this->categories[$id] ?? null,
            TranslationFields::PAGE => $this->pages[$id] ?? null,
            TranslationFields::POST => $this->posts[$id] ?? null,
            default => null,
        };
    }
}
