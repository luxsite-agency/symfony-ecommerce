<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Shared\Translation;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Форми адмінки показують усі мови магазину й створюють переклад на кожну (translate()). Перед flush порожні
 * переклади неголовних мов знімаються, щоб у БД не було рядків з порожньою назвою. Головна мова лишається:
 * її обов'язковість перевіряє форма.
 */
#[AsDoctrineListener(event: Events::preFlush)]
final class BlankTranslationRemover
{
    public function __construct(#[Autowire('%app.default_locale%')] private readonly string $defaultLocale)
    {
    }

    public function preFlush(PreFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $entities = $uow->getScheduledEntityInsertions();
        foreach ($uow->getIdentityMap() as $managed) {
            foreach ($managed as $entity) {
                $entities[] = $entity;
            }
        }

        foreach ($entities as $entity) {
            if (!$entity instanceof TranslatableInterface) {
                continue;
            }
            foreach ($entity->getTranslatedLocales() as $locale) {
                if ($locale === $this->defaultLocale) {
                    continue;
                }
                $translation = $entity->getTranslation($locale);
                if (null !== $translation && $translation->isBlank()) {
                    $entity->removeTranslation($locale);
                    // новий переклад уже може бути в чергі вставки через cascade persist — прибрати і звідти
                    $em->remove($translation);
                }
            }
        }
    }
}
