<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Changeset\Resolve;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Changeset\Dto\Operation;
use App\Core\Changeset\Dto\RemovePriceTier;
use App\Core\Changeset\Dto\SetPriceTier;
use App\Core\Changeset\Dto\SetProductCategories;
use App\Core\Changeset\Dto\SetProductFlags;
use App\Core\Changeset\Dto\SetTranslation;
use App\Core\Changeset\Dto\SetVariantPrice;
use App\Core\Changeset\Validate\TranslationFields;
use App\Core\Content\Entity\Page;
use App\Core\Content\Entity\Post;
use App\Core\Customer\Entity\CustomerGroup;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Завантажує все, на що посилається пакет, однією вибіркою на тип (варіанти за id і за SKU, товари, категорії, сторінки,
 * записи, групи покупців за кодом). При застосуванні — з PESSIMISTIC_WRITE (SELECT … FOR UPDATE у транзакції аплаєра)
 * і HINT_REFRESH (значення з БД, а не з identity map), щоб між повторною перевіркою expect і flush ніхто не змінив рядок.
 * Крім коренів блокуються й залежні рядки, які форма адмінки міняє без дотику до кореня (рев'ю C #72, M3): переклади цілей
 * set_translation, пороги варіантів цінових операцій, зв'язки товар–категорія для set_product_categories — DBAL-ом
 * SELECT … FOR UPDATE до оцінки, далі Doctrine читає їх уже під блокуванням цієї ж транзакції.
 */
final class ChangesetResolver
{
    /** @var array<string, array{string, string}> тип set_translation → таблиця перекладів і колонка кореня */
    private const array TRANSLATION_TABLES = [
        TranslationFields::PRODUCT => ['catalog_product_translation', 'product_id'],
        TranslationFields::CATEGORY => ['catalog_category_translation', 'category_id'],
        TranslationFields::PAGE => ['content_page_translation', 'page_id'],
        TranslationFields::POST => ['content_post_translation', 'post_id'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @param list<Operation> $operations */
    public function load(array $operations, bool $lock = false): ResolvedTargets
    {
        $variantIds = $variantSkus = $productIds = $productSkus = $categoryIds = $pageIds = $postIds = $groupCodes = [];
        foreach ($operations as $op) {
            if ($op instanceof SetVariantPrice || $op instanceof SetPriceTier || $op instanceof RemovePriceTier) {
                self::ref($op->variant, $variantIds, $variantSkus);
            }
            if (($op instanceof SetPriceTier || $op instanceof RemovePriceTier) && null !== $op->customerGroup) {
                $groupCodes[] = $op->customerGroup;
            }
            if ($op instanceof SetProductFlags || $op instanceof SetProductCategories) {
                self::ref($op->product, $productIds, $productSkus);
            }
            if ($op instanceof SetProductCategories) {
                array_push($categoryIds, $op->main, ...$op->categories);
            }
            if ($op instanceof SetTranslation) {
                match ($op->entity) {
                    TranslationFields::PRODUCT => $productIds[] = $op->id,
                    TranslationFields::CATEGORY => $categoryIds[] = $op->id,
                    TranslationFields::PAGE => $pageIds[] = $op->id,
                    TranslationFields::POST => $postIds[] = $op->id,
                    default => null,
                };
            }
        }

        $targets = new ResolvedTargets();
        foreach ($this->fetch(ProductVariant::class, 'sku', $variantSkus, $lock) as $variant) {
            $targets->addVariant($variant);
            $productIds[] = (int) $variant->getProduct()->getId();
        }
        // товар за SKU — через його варіант
        foreach ($this->fetch(ProductVariant::class, 'sku', $productSkus, $lock) as $variant) {
            $targets->addProductBySku($variant->getSku(), $variant->getProduct());
            $productIds[] = (int) $variant->getProduct()->getId();
        }
        foreach ($this->fetch(ProductVariant::class, 'id', $variantIds, $lock) as $variant) {
            $targets->addVariant($variant);
            $productIds[] = (int) $variant->getProduct()->getId();
        }
        foreach ($this->fetch(Product::class, 'id', $productIds, $lock) as $product) {
            $targets->addProduct($product);
        }
        foreach ($this->fetch(Category::class, 'id', $categoryIds, $lock) as $category) {
            $targets->addCategory($category);
        }
        foreach ($this->fetch(Page::class, 'id', $pageIds, $lock) as $page) {
            $targets->addPage($page);
        }
        foreach ($this->fetch(Post::class, 'id', $postIds, $lock) as $post) {
            $targets->addPost($post);
        }
        foreach ($this->fetch(CustomerGroup::class, 'code', $groupCodes, false) as $group) {
            $targets->addGroup($group);
        }
        if ($lock) {
            $this->lockDependents($operations, $targets);
        }

        return $targets;
    }

    /**
     * FOR UPDATE на переклади, пороги й зв'язки категорій цілей (лише ті таблиці, яких пакет торкається).
     *
     * @param list<Operation> $operations
     */
    private function lockDependents(array $operations, ResolvedTargets $targets): void
    {
        $translations = [];
        $tierVariants = [];
        $categoryProducts = [];
        foreach ($operations as $op) {
            if ($op instanceof SetPriceTier || $op instanceof RemovePriceTier) {
                $variant = $targets->variant($op->variant);
                if (null !== $variant) {
                    $tierVariants[] = (int) $variant->getId();
                }
            } elseif ($op instanceof SetProductCategories) {
                $product = $targets->product($op->product);
                if (null !== $product) {
                    $categoryProducts[] = (int) $product->getId();
                }
            } elseif ($op instanceof SetTranslation && null !== $targets->translatable($op->entity, $op->id)) {
                $translations[$op->entity][] = $op->id;
            }
        }
        foreach ($translations as $type => $ids) {
            [$table, $column] = self::TRANSLATION_TABLES[$type];
            $this->lockRows($table, $column, $ids);
        }
        $this->lockRows('catalog_price_tier', 'variant_id', $tierVariants);
        $this->lockRows('catalog_product_category', 'product_id', $categoryProducts);
    }

    /** @param list<int> $ids */
    private function lockRows(string $table, string $column, array $ids): void
    {
        $ids = array_values(array_unique($ids));
        if ([] === $ids) {
            return;
        }
        $this->em->getConnection()->executeQuery(\sprintf('SELECT %s FROM %s WHERE %s IN (?) FOR UPDATE', $column, $table, $column), [$ids], [ArrayParameterType::INTEGER])->fetchAllAssociative();
    }

    /**
     * @param list<int>    $ids
     * @param list<string> $skus
     */
    private static function ref(int|string $ref, array &$ids, array &$skus): void
    {
        if (\is_int($ref)) {
            $ids[] = $ref;
        } else {
            $skus[] = $ref;
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T>  $class
     * @param list<int|string> $values
     *
     * @return list<T>
     */
    private function fetch(string $class, string $field, array $values, bool $lock): array
    {
        $values = array_values(array_unique($values));
        if ([] === $values) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('e')->from($class, 'e')
            ->where(\sprintf('e.%s IN (:values)', $field))->setParameter('values', $values)
            ->getQuery();
        if ($lock) {
            // без HINT_REFRESH Doctrine лишає значення з identity map — блокування було б, а CAS порівнював би старе
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true);
        }

        /** @var list<T> */
        return $query->getResult();
    }
}
