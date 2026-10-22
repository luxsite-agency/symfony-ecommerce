<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Site\Solution;

use App\Core\Content\Entity\Solution;
use App\Core\Content\Entity\SolutionTranslation;
use App\Core\Content\SolutionLocales;
use App\Site\SiteNavigation;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Зміна рішення чи його перекладу (публікація, назва, слаг, видалення) скидає кеш мов з рішеннями — у postFlush, після коміту:
 * у onFlush паралельний запит міг би перерахувати кеш зі старих даних до коміту (рев'ю #102, L6).
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class SolutionsCacheListener
{
    private bool $changed = false;

    public function __construct(private readonly SolutionLocales $locales, private readonly SiteNavigation $navigation)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ([$uow->getScheduledEntityInsertions(), $uow->getScheduledEntityUpdates(), $uow->getScheduledEntityDeletions()] as $entities) {
            foreach ($entities as $entity) {
                if ($entity instanceof Solution || $entity instanceof SolutionTranslation) {
                    $this->changed = true;

                    return;
                }
            }
        }
    }

    public function postFlush(): void
    {
        if ($this->changed) {
            $this->changed = false;
            $this->locales->forget();
            $this->navigation->reset();
        }
    }
}
