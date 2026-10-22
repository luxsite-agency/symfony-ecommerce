<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Redirect;

use App\Core\Catalog\Entity\ProductTranslation;
use App\Core\Content\Entity\PageTranslation;
use App\Core\Content\Entity\PostTranslation;
use App\Core\Seo\SlugChange;
use App\Core\Seo\SlugChangeRecorder;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Авто-301 на зміну слага товару, сторінки й статті через ORM (адмінка, імпорт). Старий слаг — зі змін onFlush, новий —
 * після flush: SlugListener у preUpdate ще може дописати суфікс (-2) чи взяти слаг із заголовка. Шляхи категорій —
 * окремо (CategoryPaths → CategoryPathsChanged), бо вони змінюються й каскадом. Новий переклад зі слагом знімає
 * правило з тієї ж адреси (AutoRedirects). Видалення чи вимкнення сутності правил
 * не створює (рішення E3: 410 — лише вручну).
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class SlugChangeListener
{
    /** @var array<int, array{0: ProductTranslation|PageTranslation|PostTranslation, 1: string|null}> null — вставка або слаг з'явився */
    private array $changed = [];

    public function __construct(private readonly SlugChangeRecorder $recorder)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (self::supports($entity)) {
                $this->changed[spl_object_id($entity)] ??= [$entity, null];
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $changeSet = $uow->getEntityChangeSet($entity);
            if (self::supports($entity) && \array_key_exists('slug', $changeSet)) {
                $old = $changeSet['slug'][0];
                $this->changed[spl_object_id($entity)] ??= [$entity, \is_string($old) && '' !== $old ? $old : null];
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->changed) {
            return;
        }
        $changes = [];
        foreach ($this->changed as [$translation, $old]) {
            $new = $translation->getSlug();
            if (null !== $new && $new !== $old) {
                $type = match (true) {
                    $translation instanceof ProductTranslation => SlugChange::PRODUCT,
                    $translation instanceof PageTranslation => SlugChange::PAGE,
                    default => SlugChange::POST,
                };
                $changes[] = new SlugChange($type, $translation->getLocale(), $old, $new);
            }
        }
        $this->changed = [];
        if ([] !== $changes) {
            $this->recorder->record($changes);
        }
    }

    /** @phpstan-assert-if-true ProductTranslation|PageTranslation|PostTranslation $entity */
    private static function supports(object $entity): bool
    {
        return $entity instanceof ProductTranslation || $entity instanceof PageTranslation || $entity instanceof PostTranslation;
    }
}
