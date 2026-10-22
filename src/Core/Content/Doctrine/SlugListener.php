<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\Content\Doctrine;

use App\Core\Content\Entity\PageTranslation;
use App\Core\Content\Entity\PostCategoryTranslation;
use App\Core\Content\Entity\PostTranslation;
use App\Core\Content\Entity\SolutionTranslation;
use App\Core\Content\Url\PageSlugs;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Слаг перекладу контенту (Page/Post/рубрика/рішення) з заголовка, якщо порожній, унікальний у межах локалі (-2, -3…).
 * За зразком внутрішнього проєкту LuxSite, але слаг per-locale (у нас URL перекладаються: /page/dostavka і /ru/page/dostavka можуть різнитись).
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class SlugListener
{
    public function __construct(#[Target('urlSlugger')] private readonly SluggerInterface $slugger, private readonly PageSlugs $pageSlugs)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($this->supports($entity)) {
            $entity->setSlug($this->resolve($args->getObjectManager(), $entity));
        }
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->supports($entity)) {
            return;
        }
        $slug = $this->resolve($args->getObjectManager(), $entity);
        if ($slug === $entity->getSlug()) {
            return;
        }
        $entity->setSlug($slug);
        if ($args->hasChangedField('slug')) {
            $args->setNewValue('slug', $slug);
        } else {
            $em = $args->getObjectManager();
            $em->getUnitOfWork()->recomputeSingleEntityChangeSet($em->getClassMetadata($entity::class), $entity);
        }
    }

    /** @phpstan-assert-if-true PageTranslation|PostTranslation|PostCategoryTranslation|SolutionTranslation $entity */
    private function supports(object $entity): bool
    {
        return $entity instanceof PageTranslation || $entity instanceof PostTranslation || $entity instanceof PostCategoryTranslation || $entity instanceof SolutionTranslation;
    }

    private function resolve(EntityManagerInterface $em, PageTranslation|PostTranslation|PostCategoryTranslation|SolutionTranslation $entity): ?string
    {
        $source = $entity->getSlug() ?? $entity->getTitle();
        if ('' === trim($source)) {
            return null; // порожній переклад — слаг лишається порожнім, публічно він і так не показується
        }
        $base = mb_substr(trim($this->slugger->slug($source, '-', $entity->getLocale())->lower()->toString(), '-'), 0, 180) ?: 'item';
        $ownerId = match (true) {
            $entity instanceof PageTranslation => $entity->getPage()->getId(),
            $entity instanceof PostTranslation => $entity->getPost()->getId(),
            $entity instanceof SolutionTranslation => $entity->getSolution()->getId(),
            default => $entity->getCategory()->getId(),
        };
        $ownerField = match (true) {
            $entity instanceof PageTranslation => 'page',
            $entity instanceof PostTranslation => 'post',
            $entity instanceof SolutionTranslation => 'solution',
            default => 'category',
        };

        $slug = $base;
        for ($n = 2; $n < 1000; ++$n) {
            $qb = $em->createQueryBuilder()->select('COUNT(t.id)')->from($entity::class, 't')
                ->where('t.locale = :locale')->andWhere('t.slug = :slug')
                ->setParameter('locale', $entity->getLocale())->setParameter('slug', $slug);
            if (null !== $ownerId) {
                $qb->andWhere(\sprintf('IDENTITY(t.%s) != :owner', $ownerField))->setParameter('owner', $ownerId);
            }
            // сторінки в корені сайту: ще й не категорія, не товар і не службова адреса (PageSlugs)
            $rootClash = $entity instanceof PageTranslation && null !== $this->pageSlugs->conflict($slug, $entity->getLocale());
            if (!$rootClash && 0 === (int) $qb->getQuery()->getSingleScalarResult()) {
                return $slug;
            }
            $slug = $base.'-'.$n;
        }

        return $base.'-'.bin2hex(random_bytes(3));
    }
}
