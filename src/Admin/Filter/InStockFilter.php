<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Filter;

use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\BooleanFilterType;

/**
 * «У наявності» для списку товарів: є хоча б один активний варіант із залишком > 0 або з політикою без обліку/під замовлення.
 * Не поле сутності — EXISTS по варіантах, без join і дублів рядків.
 */
final class InStockFilter implements FilterInterface
{
    use FilterTrait;

    public static function new(string $propertyName = 'inStock', string $label = 'filter.in_stock'): self
    {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(BooleanFilterType::class)
            ->setFormTypeOption('translation_domain', 'EasyAdminBundle');
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $alias = $filterDataDto->getEntityAlias();
        $exists = \sprintf(
            'EXISTS (SELECT 1 FROM App\Core\Catalog\Entity\ProductVariant fv WHERE fv.product = %s AND fv.active = true AND (fv.stock > 0 OR fv.stockPolicy <> :in_stock_policy))',
            $alias,
        );
        $queryBuilder
            ->andWhere(true === $filterDataDto->getValue() ? $exists : 'NOT '.$exists)
            ->setParameter('in_stock_policy', 'in_stock');
    }
}
