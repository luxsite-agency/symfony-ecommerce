<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Audit;

use BackedEnum;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use UnitEnum;

/**
 * Журнал змін з UnitOfWork (E15 PR-1): у onFlush, якщо є актор (AdminAuditContext), збирає зміни журнальованих
 * сутностей — поле за полем для update, знімок для insert/delete, діф ManyToMany-колекцій (категорії товару, межі акції);
 * у postFlush (id нових уже відомі) пише рядки DBAL-ом. Без актора (імпорт, воркер, сайт) — нічого. Зміни SQL-ом
 * повз ORM (batch цін імпорту) сюди не потрапляють — як і в read-index; у них власний звіт запуску.
 *
 * Рев'ю C (#68): preUpdate з нижчим пріоритетом за SlugListener підправляє «стало» полів, які перерахували інші слухачі
 * (слаг сторінки з заголовка); стан скидається на початку onFlush і в onClear, щоб після невдалого flush (postFlush не
 * викликається) рядки відкочених змін не долипли до наступного; ManyToMany нової сутності йде у знімок create, не окремим update.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::preUpdate, priority: -32)]
#[AsDoctrineListener(event: Events::postFlush)]
#[AsDoctrineListener(event: Events::onClear)]
final class AdminAuditDoctrineListener
{
    /** @var list<array{action: AuditAction, root: object, type: string, locale: string|null, field: string|null, before: mixed, after: mixed, id: int|null, label: string|null}> */
    private array $pending = [];
    private ?AuditActor $actor = null;

    public function __construct(private readonly AdminAuditContext $context, private readonly AdminAuditLog $log)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $this->reset();
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $candidates = false;
        foreach ([$uow->getScheduledEntityInsertions(), $uow->getScheduledEntityUpdates(), $uow->getScheduledEntityDeletions()] as $set) {
            foreach ($set as $entity) {
                if (AuditedEntities::isAudited($entity)) {
                    $candidates = true;
                    break 2;
                }
            }
        }
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            if (AuditedEntities::isAudited($collection->getOwner())) {
                $candidates = true;
            }
        }
        if (!$candidates) {
            return;
        }
        $this->actor = $this->context->current();
        if (null === $this->actor) {
            return;
        }

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $target = AuditedEntities::target($entity);
            if (null === $target) {
                continue;
            }
            $this->pending[] = $this->row(AuditAction::Create, $target, null, null, $this->snapshot(self::newValues($uow->getEntityChangeSet($entity))));
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $target = AuditedEntities::target($entity);
            if (null === $target) {
                continue;
            }
            foreach (self::fieldChanges($uow->getEntityChangeSet($entity)) as $field => [$old, $new]) {
                if (AuditedEntities::isIgnoredField($field)) {
                    continue;
                }
                $old = self::normalize($old);
                $new = self::normalize($new);
                if ($old === $new) {
                    continue;
                }
                $this->pending[] = $this->row(AuditAction::Update, $target, $field, $old, $new);
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $target = AuditedEntities::target($entity);
            if (null === $target) {
                continue;
            }
            $meta = $em->getClassMetadata($entity::class);
            $this->pending[] = $this->row(AuditAction::Delete, $target, null, $this->snapshot(self::originalData($uow, $meta, $entity)), null);
        }
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            $this->collection($collection, $uow);
        }
    }

    /**
     * Інші preUpdate-слухачі (SlugListener) можуть змінити значення вже після onFlush: синхронізуємо «стало» з фактичним
     * changeset сутності, прибираємо рядки, що стали порожніми, додаємо поля, яких у onFlush ще не було.
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        if (null === $this->actor) {
            return;
        }
        $entity = $args->getObject();
        $target = AuditedEntities::target($entity);
        if (null === $target) {
            return;
        }
        foreach (self::fieldChanges($args->getEntityChangeSet()) as $field => [$old, $new]) {
            if (AuditedEntities::isIgnoredField($field)) {
                continue;
            }
            $old = AuditedEntities::redact($target['root'], $field, self::normalize($old));
            $new = AuditedEntities::redact($target['root'], $field, self::normalize($new));
            $index = $this->findPending(AuditAction::Update, $target, $field);
            if ($old === $new) {
                if (null !== $index) {
                    unset($this->pending[$index]);
                    $this->pending = array_values($this->pending);
                }
                continue;
            }
            if (null === $index) {
                $this->pending[] = $this->row(AuditAction::Update, $target, $field, $old, $new);
            } else {
                $this->pending[$index]['after'] = $new;
            }
        }
    }

    public function onClear(OnClearEventArgs $args): void
    {
        $this->reset();
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $actor = $this->actor;
        $pending = $this->pending;
        $this->reset();
        if (null === $actor || [] === $pending) {
            return;
        }
        $rows = [];
        foreach ($pending as $p) {
            $id = $p['id'] ?? (method_exists($p['root'], 'getId') ? $p['root']->getId() : null);
            if (!\is_int($id)) {
                continue;
            }
            $rows[] = [
                'action' => $p['action'],
                'entity_type' => $p['type'],
                'entity_id' => $id,
                'entity_label' => $p['label'] ?? AuditedEntities::label($p['root']),
                'locale' => $p['locale'],
                'field' => $p['field'],
                'before' => $p['before'],
                'after' => $p['after'],
            ];
        }
        $this->log->write($actor, $rows);
    }

    private function reset(): void
    {
        $this->pending = [];
        $this->actor = null;
    }

    /** @param array{type: string, root: object, locale: string|null} $target */
    private function findPending(AuditAction $action, array $target, ?string $field): ?int
    {
        foreach ($this->pending as $i => $p) {
            if ($p['action'] === $action && $p['root'] === $target['root'] && $p['locale'] === $target['locale'] && $p['field'] === $field) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array{type: string, root: object, locale: string|null} $target
     *
     * @return array{action: AuditAction, root: object, type: string, locale: string|null, field: string|null, before: mixed, after: mixed, id: int|null, label: string|null}
     */
    private function row(AuditAction $action, array $target, ?string $field, mixed $before, mixed $after): array
    {
        $root = $target['root'];
        // при видаленні id і підпис беремо зараз: після flush сутність від'єднана, а батько перекладу може зникнути разом із ним
        $now = AuditAction::Delete === $action;
        $id = $now && method_exists($root, 'getId') ? $root->getId() : null;

        return [
            'action' => $action,
            'root' => $root,
            'type' => $target['type'],
            'locale' => $target['locale'],
            'field' => $field,
            'before' => AuditedEntities::redact($root, $field, $before),
            'after' => AuditedEntities::redact($root, $field, $after),
            'id' => \is_int($id) ? $id : null,
            'label' => $now ? AuditedEntities::label($root) : null,
        ];
    }

    /** @param PersistentCollection<int, object> $collection */
    private function collection(PersistentCollection $collection, UnitOfWork $uow): void
    {
        $mapping = $collection->getMapping();
        if (!$mapping->isManyToMany()) {
            return;
        }
        $owner = $collection->getOwner();
        if (null === $owner) {
            return;
        }
        $target = AuditedEntities::target($owner);
        if (null === $target) {
            return;
        }
        $before = self::ids($collection->getSnapshot());
        $after = self::ids($collection->toArray());
        if ($before === $after) {
            return;
        }
        if ($uow->isScheduledForInsert($owner)) {
            // нова сутність з категоріями: список id у знімку create, а не окремий рядок update «[] → [ids]»
            $index = $this->findPending(AuditAction::Create, $target, null);
            if (null !== $index && \is_array($this->pending[$index]['after']) && [] !== $after) {
                $this->pending[$index]['after'][$mapping->fieldName] = $after;
            }

            return;
        }
        $this->pending[] = $this->row(AuditAction::Update, $target, $mapping->fieldName, $before, $after);
    }

    /**
     * Зміни полів із changeset UnitOfWork: пари [старе, нове]; колекції (PersistentCollection) — не сюди.
     *
     * @param array<string, mixed> $changeSet
     *
     * @return array<string, array{mixed, mixed}>
     */
    private static function fieldChanges(array $changeSet): array
    {
        $out = [];
        foreach ($changeSet as $field => $change) {
            if (\is_array($change) && 2 === \count($change) && array_is_list($change) && !$change[0] instanceof Collection && !$change[1] instanceof Collection) {
                $out[$field] = [$change[0], $change[1]];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $changeSet
     *
     * @return array<string, mixed>
     */
    private static function newValues(array $changeSet): array
    {
        return array_map(static fn (array $change): mixed => $change[1], self::fieldChanges($changeSet));
    }

    /**
     * Знімок полів без технічних і порожніх; колекції — не сюди (дочірні сутності мають власні рядки).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function snapshot(array $data): array
    {
        $out = [];
        foreach ($data as $field => $value) {
            if (AuditedEntities::isIgnoredField($field) || $value instanceof PersistentCollection) {
                continue;
            }
            $value = self::normalize($value);
            if (null !== $value) {
                $out[$field] = $value;
            }
        }

        return $out;
    }

    /**
     * @param ClassMetadata<object> $meta
     *
     * @return array<string, mixed>
     */
    private static function originalData(UnitOfWork $uow, ClassMetadata $meta, object $entity): array
    {
        $data = [];
        foreach ($uow->getOriginalEntityData($entity) as $field => $value) {
            if ($meta->hasField($field) || ($meta->hasAssociation($field) && $meta->isSingleValuedAssociation($field))) {
                $data[$field] = $value;
            }
        }

        return $data;
    }

    /**
     * @param iterable<object> $entities
     *
     * @return list<int>
     */
    private static function ids(iterable $entities): array
    {
        $ids = [];
        foreach ($entities as $entity) {
            $id = method_exists($entity, 'getId') ? $entity->getId() : null;
            if (\is_int($id)) {
                $ids[] = $id;
            }
        }
        sort($ids);

        return $ids;
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof PersistentCollection => $value,
            \is_object($value) && method_exists($value, 'getId') => $value->getId(),
            \is_object($value) => $value::class,
            default => $value,
        };
    }
}
