<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Catalog\Url;

use App\Core\Catalog\Entity\Category;
use App\Core\Catalog\Entity\CategoryTranslation;
use App\Core\Catalog\Url\Message\RebuildCategoryPaths;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Шлях категорії змінюється зі слагом перекладу чи батьком: після flush шлях самої категорії перераховується одразу
 * (адмінка одразу показує нову адресу), нащадків — фоново (RebuildCategoryPaths).
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class CategoryPathListener
{
    /** @var array<int, Category> */
    private array $changed = [];

    public function __construct(private readonly CategoryPaths $paths, private readonly MessageBusInterface $bus)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->collect($entity, null);
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->collect($entity, array_keys($uow->getEntityChangeSet($entity)));
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->changed) {
            return;
        }
        $ids = [];
        foreach ($this->changed as $category) {
            if (null !== $category->getId()) {
                $ids[] = (int) $category->getId();
            }
        }
        $this->changed = [];
        if ([] === $ids) {
            return;
        }
        // сам вузол (і нові діти в тому ж flush — вони теж у $ids) — одразу, нащадки вже збережених — фоново
        $this->paths->rebuild($ids, false);
        $this->paths->syncManaged($args->getObjectManager());
        $children = $args->getObjectManager()->getConnection()->fetchFirstColumn('SELECT id FROM catalog_category WHERE parent_id IN (?) AND id NOT IN (?) LIMIT 1', [$ids, $ids], [ArrayParameterType::INTEGER, ArrayParameterType::INTEGER]);
        if ([] !== $children) {
            $this->bus->dispatch(new RebuildCategoryPaths($ids));
        }
    }

    /** @param list<string>|null $changedFields null — вставка */
    private function collect(object $entity, ?array $changedFields): void
    {
        if ($entity instanceof CategoryTranslation && (null === $changedFields || \in_array('slug', $changedFields, true))) {
            $this->changed[spl_object_id($entity->getCategory())] = $entity->getCategory();
        } elseif ($entity instanceof Category && (null === $changedFields || \in_array('parent', $changedFields, true))) {
            $this->changed[spl_object_id($entity)] = $entity;
        }
    }
}
