<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\FilterIndex;

use App\Core\Catalog\Entity\Attribute;
use App\Core\Catalog\Entity\AttributeTranslation;
use App\Core\Catalog\Entity\AttributeValue;
use App\Core\Catalog\Entity\AttributeValueTranslation;
use App\Core\Catalog\Entity\Brand;
use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\CategoryFilterAttribute;
use App\Core\Catalog\Entity\CategoryTranslation;
use App\Core\Catalog\Entity\Product;
use App\Core\Catalog\Entity\ProductAttributeValue;
use App\Core\Catalog\Entity\ProductVariant;
use App\Core\Catalog\Entity\VariantAxisValue;
use App\Core\Catalog\FilterIndex\Message\ReindexProducts;
use App\Core\Catalog\Query\CatalogCacheVersion;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\PersistentCollection;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Тримає read-index у синхроні з ORM-змінами (ADR Rev 9): у onFlush класифікує зміни UnitOfWork, у postFlush
 * (після коміту; всередині зовнішньої транзакції, якщо flush загорнуто в wrapInTransaction) виконує точкові UPDATE
 * і диспатчить ReindexProducts. Прапорці/ціна варіанта, active/price_mode/brand товару → UPDATE одразу; характеристики,
 * осі, категорії, нові/видалені варіанти й товари → регенерація через Messenger; перенесення чи видалення категорії →
 * регенерація товарів усього піддерева; видалення значення характеристики (FK CASCADE в обхід ORM) → регенерація
 * товарів, що його мали (id збираються в onFlush, поки рядки ще є). Зміна Attribute.filterable/variantAxis — лише CLI
 * catalog:filter-index:rebuild (зачіпає потенційно весь каталог).
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class FilterIndexDoctrineListener
{
    private const int MESSAGE_CHUNK = 200;
    private const array VARIANT_POINT_FIELDS = ['priceMinor', 'stock', 'stockPolicy', 'active'];
    private const array PRODUCT_POINT_FIELDS = ['active', 'priceMode', 'brand'];

    /** @var array<int, true> */
    private array $reindexProducts = [];
    /** @var array<int, true> */
    private array $deleteProducts = [];
    /** @var array<int, true> */
    private array $refreshVariants = [];
    /** @var array<int, true> */
    private array $refreshProducts = [];
    /** @var array<int, true> */
    private array $movedCategories = [];
    /** @var list<Product> нові товари: id відомий лише після flush */
    private array $newProducts = [];
    /** @var list<ProductVariant> нові варіанти вже збережених товарів */
    private array $newVariants = [];
    /** Змінилось те, що кешується поза індексом (фасетні блоки, дерево, характеристики, бренди) — bump версії кешу. */
    private bool $bumpCache = false;

    public function __construct(
        private readonly FilterIndexer $indexer,
        private readonly Connection $db,
        private readonly MessageBusInterface $bus,
        private readonly CatalogCacheVersion $cacheVersion,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->classify($entity, 'insert', []);
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->classify($entity, 'update', array_keys($uow->getEntityChangeSet($entity)));
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $this->classify($entity, 'delete', []);
        }
        foreach ([...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()] as $collection) {
            $this->classifyCollection($collection);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        foreach ($this->newProducts as $product) {
            if (null !== $product->getId()) {
                $this->reindexProducts[$product->getId()] = true;
            }
        }
        foreach ($this->newVariants as $variant) {
            $productId = $variant->getProduct()->getId();
            if (null !== $productId) {
                $this->reindexProducts[$productId] = true;
            }
        }
        foreach (array_keys($this->movedCategories) as $categoryId) {
            foreach ($this->productsOfSubtree($categoryId) as $productId) {
                $this->reindexProducts[$productId] = true;
            }
        }

        $reindex = array_keys(array_diff_key($this->reindexProducts, $this->deleteProducts));
        $deleted = array_keys($this->deleteProducts);
        $refreshProducts = array_keys(array_diff_key($this->refreshProducts, $this->reindexProducts, $this->deleteProducts));
        $refreshVariants = array_keys($this->refreshVariants);
        $bumpCache = $this->bumpCache;
        $this->reset();

        if ($bumpCache) {
            $this->cacheVersion->bump();
        }

        if ([] === $reindex && [] === $deleted && [] === $refreshProducts && [] === $refreshVariants) {
            return;
        }

        if ([] !== $deleted) {
            $this->indexer->deleteProducts($deleted);
        }
        if ([] !== $refreshVariants) {
            $this->indexer->refreshVariants($refreshVariants);
        }
        if ([] !== $refreshProducts) {
            $this->indexer->refreshProductVariants($refreshProducts);
        }
        foreach (array_chunk($reindex, self::MESSAGE_CHUNK) as $chunk) {
            $this->bus->dispatch(new ReindexProducts($chunk));
        }
    }

    /** @param list<string> $changed */
    private function classify(object $entity, string $operation, array $changed): void
    {
        if ($entity instanceof CategoryFilterAttribute || $entity instanceof Category || $entity instanceof Attribute
            || $entity instanceof AttributeValue || $entity instanceof Brand
            // підписи фасетів і дерева мовою сторінки (Site\Catalog\CatalogLabels) кешуються в тій самій версії
            || $entity instanceof AttributeTranslation || $entity instanceof AttributeValueTranslation || $entity instanceof CategoryTranslation) {
            $this->bumpCache = true;
        }
        switch (true) {
            case $entity instanceof Product:
                if ('insert' === $operation) {
                    $this->newProducts[] = $entity;
                } elseif ('delete' === $operation) {
                    $this->mark($this->deleteProducts, $entity->getId());
                } elseif ([] !== array_intersect($changed, self::PRODUCT_POINT_FIELDS)) {
                    $this->mark($this->refreshProducts, $entity->getId());
                }
                break;
            case $entity instanceof ProductVariant:
                $productId = $entity->getProduct()->getId();
                if ('update' === $operation) {
                    if ([] !== array_intersect($changed, self::VARIANT_POINT_FIELDS)) {
                        $this->mark($this->refreshVariants, $entity->getId());
                    }
                } elseif (null === $productId || 'insert' === $operation) {
                    $this->newVariants[] = $entity; // id товару/варіанта відомі після flush
                } else {
                    $this->mark($this->reindexProducts, $productId);
                }
                break;
            case $entity instanceof ProductAttributeValue:
                $this->markProduct($entity->getProduct());
                break;
            case $entity instanceof VariantAxisValue:
                $this->markProduct($entity->getVariant()->getProduct());
                break;
            case $entity instanceof Category:
                if (null === $entity->getId()) {
                    break;
                }
                if ('delete' === $operation) {
                    // після DELETE піддерева вже не знайти — збираємо товари зараз
                    foreach ($this->productsOfSubtree($entity->getId()) as $productId) {
                        $this->reindexProducts[$productId] = true;
                    }
                } elseif ('update' === $operation && \in_array('parent', $changed, true)) {
                    $this->movedCategories[$entity->getId()] = true;
                }
                break;
            case $entity instanceof AttributeValue:
                if ('delete' === $operation && null !== $entity->getId()) {
                    foreach ($this->productsWithOption($entity->getId()) as $productId) {
                        $this->reindexProducts[$productId] = true;
                    }
                }
                break;
        }
    }

    /** @param Collection<int|string, mixed> $collection */
    private function classifyCollection(Collection $collection): void
    {
        if (!$collection instanceof PersistentCollection) {
            return;
        }
        $owner = $collection->getOwner();
        $mapping = $collection->getMapping();
        if ($owner instanceof Product && 'categories' === $mapping->fieldName) {
            $this->markProduct($owner);
        }
    }

    private function markProduct(Product $product): void
    {
        if (null === $product->getId()) {
            $this->newProducts[] = $product;
        } else {
            $this->reindexProducts[$product->getId()] = true;
        }
    }

    /** @param array<int, true> $set */
    private function mark(array &$set, ?int $id): void
    {
        if (null !== $id) {
            $set[$id] = true;
        }
    }

    /** @return list<int> */
    private function productsOfSubtree(int $categoryId): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->db->fetchFirstColumn(
            'WITH RECURSIVE sub AS (SELECT id FROM catalog_category WHERE id = ? UNION ALL SELECT c.id FROM catalog_category c JOIN sub ON c.parent_id = sub.id)
             SELECT DISTINCT pc.product_id FROM catalog_product_category pc JOIN sub ON sub.id = pc.category_id',
            [$categoryId],
        );

        return array_map(intval(...), $ids);
    }

    /** @return list<int> */
    private function productsWithOption(int $attributeValueId): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->db->fetchFirstColumn(
            'SELECT product_id FROM catalog_product_attribute_value WHERE option_id = ?
             UNION SELECT v.product_id FROM catalog_product_variant_axis_value ax JOIN catalog_product_variant v ON v.id = ax.variant_id WHERE ax.attribute_value_id = ?',
            [$attributeValueId, $attributeValueId],
        );

        return array_map(intval(...), $ids);
    }

    private function reset(): void
    {
        $this->reindexProducts = $this->deleteProducts = $this->refreshVariants = $this->refreshProducts = $this->movedCategories = [];
        $this->newProducts = $this->newVariants = [];
        $this->bumpCache = false;
    }
}
