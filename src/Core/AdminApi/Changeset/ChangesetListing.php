<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Core\AdminApi\Changeset;

use App\Core\Changeset\ChangesetStatus;
use App\Core\Changeset\Entity\Changeset;
use App\Core\Security\Entity\User;
use App\Core\Security\Enum\UserRole;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Читання пакетів для REST і MCP (E15 PR-4/5): свої, найновіші перші; адміністратор з all — усі. Чужий пакет не існує
 * (null → 404 у REST, помилка інструмента в MCP): існування не підтверджуємо. Фільтр статусу — за effectiveStatus
 * (прострочений proposed читається як expired).
 */
final class ChangesetListing
{
    public const int MAX_LIMIT = 100;

    public function __construct(private readonly EntityManagerInterface $em, private readonly AuthorizationCheckerInterface $auth)
    {
    }

    /** @return array{items: list<Changeset>, total: int, page: int, limit: int} */
    public function list(User $user, bool $all, ?ChangesetStatus $status, int $limit, int $page): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $page = max(1, $page);
        $qb = $this->em->createQueryBuilder()->select('c')->from(Changeset::class, 'c')->orderBy('c.createdAt', 'DESC')->addOrderBy('c.id', 'DESC');
        if (!($all && $this->auth->isGranted(UserRole::Admin->value))) {
            $qb->andWhere('c.user = :me')->setParameter('me', $user);
        }
        if (null !== $status) {
            $now = new DateTimeImmutable();
            match ($status) {
                ChangesetStatus::Proposed => $qb->andWhere('c.status = :proposed AND c.expiresAt > :now')->setParameter('proposed', ChangesetStatus::Proposed)->setParameter('now', $now),
                ChangesetStatus::Expired => $qb->andWhere('c.status = :expired OR (c.status = :proposed AND c.expiresAt <= :now)')->setParameter('expired', ChangesetStatus::Expired)->setParameter('proposed', ChangesetStatus::Proposed)->setParameter('now', $now),
                default => $qb->andWhere('c.status = :status')->setParameter('status', $status),
            };
        }
        $total = (int) (clone $qb)->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<Changeset> $items */
        $items = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /** Свій пакет (адміністратор — будь-який); чужий чи неіснуючий — null. */
    public function find(int $id, User $user): ?Changeset
    {
        $changeset = $this->em->find(Changeset::class, $id);
        if (null === $changeset || (!$changeset->isOwnedBy($user) && !$this->auth->isGranted(UserRole::Admin->value))) {
            return null;
        }

        return $changeset;
    }
}
