<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin\Controller\Crud;

use App\Core\Catalog\Entity\Brand;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** @extends AbstractCrudController<Brand> */
final class BrandCrudController extends AbstractCrudController
{
    use SaveActionsTrait;

    public static function getEntityFqcn(): string
    {
        return Brand::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('brand.label.singular')
            ->setEntityLabelInPlural('brand.label.plural')
            ->setPageTitle(Crud::PAGE_NEW, 'brand.title.new')
            ->setSearchFields(['name', 'slug'])
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->configureFormActions($actions, 'brand.action.add');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'field.id')->onlyOnIndex();
        yield TextField::new('name', 'brand.field.name')
            ->setFormTypeOption('setter', static fn (Brand $brand, ?string $name) => $brand->rename((string) $name));
        yield TextField::new('slug', 'brand.field.slug')
            ->setHelp('brand.help.slug')
            ->setFormTypeOption('setter', static fn (Brand $brand, ?string $slug) => $brand->changeSlug((string) $slug));
        yield BooleanField::new('active', 'brand.field.active');
    }

    public function createEntity(string $entityFqcn): Brand
    {
        // Конструктор вимагає валідні назву й слаг; форма перепише їх через rename()/changeSlug().
        return new Brand('Новий бренд', 'novyi-brend-'.bin2hex(random_bytes(3)));
    }
}
