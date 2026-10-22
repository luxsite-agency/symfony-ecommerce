<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Search;

use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Orm\EntityRepositoryInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Пошук у списках адмінки (поле ?query=) для всіх CRUD: розділ з AdminSearcher (товари, замовлення) — його умови замість
 * типового LIKE EasyAdmin, решта — типовий пошук EasyAdmin (кожне слово в якомусь із setSearchFields). Якщо нічого не
 * знайдено, а запит схожий на набраний не в тій розкладці (KeyboardLayout), — друга спроба; що знайшли — AdminSearchState
 * в атрибуті запиту (підказка «Можливо, ви мали на увазі…», підсвітка, лічильник у crud/index.html.twig).
 * Контролери з власним createIndexQueryBuilder викликають parent — пошук і там цей.
 */
#[AsDecorator(EntityRepositoryInterface::class)]
final class SmartSearchRepository implements EntityRepositoryInterface
{
    /** @param iterable<AdminSearcher> $searchers */
    public function __construct(
        #[AutowireDecorated] private readonly EntityRepositoryInterface $inner,
        #[AutowireIterator(AdminSearcher::TAG)] private readonly iterable $searchers,
    ) {
    }

    /** @param EntityDto<object> $entityDto */
    public function createQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $query = $searchDto->getQuery();
        $qb = $this->build($query, $searchDto, $entityDto, $fields, $filters);
        if ('' === $query) {
            return $qb;
        }
        $suggestion = null;
        if (0 === self::count($qb)) {
            foreach (KeyboardLayout::alternatives($query) as $alternative) {
                $candidate = $this->build($alternative, $searchDto, $entityDto, $fields, $filters);
                if (self::count($candidate) > 0) {
                    [$qb, $suggestion] = [$candidate, $alternative];
                    break;
                }
            }
        }
        $searchDto->getRequest()->attributes->set(AdminSearchState::ATTRIBUTE, new AdminSearchState($query, $suggestion, AdminSearchQuery::terms($suggestion ?? $query)));

        return $qb;
    }

    public function searcher(string $entityFqcn): ?AdminSearcher
    {
        foreach ($this->searchers as $searcher) {
            if ($searcher->supports($entityFqcn)) {
                return $searcher;
            }
        }

        return null;
    }

    /** @param EntityDto<object> $entityDto */
    private function build(string $query, SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $searcher = '' === $query ? null : $this->searcher($entityDto->getFqcn());
        $qb = $this->inner->createQueryBuilder(self::withQuery($searchDto, null === $searcher ? $query : ''), $entityDto, $fields, $filters);
        $searcher?->apply($qb, AdminSearchQuery::terms($query));

        return $qb;
    }

    private static function withQuery(SearchDto $dto, string $query): SearchDto
    {
        if ($dto->getQuery() === $query) {
            return $dto;
        }

        return new SearchDto($dto->getRequest(), $dto->getSearchableProperties(), $query, $dto->getDefaultSort(), $dto->getCustomSort(), $dto->getAppliedFilters(), $dto->getSearchMode());
    }

    private static function count(QueryBuilder $qb): int
    {
        $count = clone $qb;
        $alias = $count->getRootAliases()[0];

        return (int) $count->select('COUNT(DISTINCT '.$alias.')')->resetDQLPart('orderBy')->setMaxResults(null)->setFirstResult(0)->getQuery()->getSingleScalarResult();
    }
}
